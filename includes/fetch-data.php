<?php
if (!defined('ABSPATH')) {
    exit;
}

$options  = get_option('bursmetre_options', []);
$burs_id  = isset($options['burs_sheet_id']) ? $options['burs_sheet_id'] : '10SUOLDAKrCbGwui9MsuH6RrjljoYtAt-CRdqPAq1Zn0';
$burs_gid = isset($options['burs_gid']) ? $options['burs_gid'] : '0';
$kamp_id  = isset($options['kamp_sheet_id']) ? $options['kamp_sheet_id'] : '1X6zi1KzN8WG2zitt_IJNjsOt1qleUmZFlt4g0P_0Tlc';
$kamp_gid = isset($options['kamp_gid']) ? $options['kamp_gid'] : '0';
$goal_raw = isset($options['goal_amount']) ? $options['goal_amount'] : '';
$goal = bursmetre_parse_amount($goal_raw);
if ($goal <= 0) {
    $goal = 8606250;
}

/**
 * Pulls Google Sheet content as CSV and caches for ten minutes.
 */
function bursmetre_fetch_csv($sheet_id, $gid = '0')
{
    $url = "https://docs.google.com/spreadsheets/d/{$sheet_id}/gviz/tq?tqx=out:csv&gid={$gid}";
    $cache_key = 'bursmetre_cache_' . md5($url);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    $response = wp_remote_get($url, ['timeout' => 15]);
    if (is_wp_error($response)) {
        return '';
    }

    $csv = wp_remote_retrieve_body($response);
    set_transient($cache_key, $csv, 10 * MINUTE_IN_SECONDS);
    return $csv;
}

/**
 * Converts CSV string into header / rows tuple.
 */
function bursmetre_csv_to_array($csv)
{
    if (trim($csv) === '') {
        return [[], []];
    }

    $lines = preg_split("/\r\n|\n|\r/", trim($csv));
    if (!$lines) {
        return [[], []];
    }

    $headers = str_getcsv(array_shift($lines));
    $rows = [];
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $rows[] = str_getcsv($line);
    }

    return [$headers, $rows];
}

/**
 * Normalises values for fuzzy matching.
 */
function bursmetre_slug($value)
{
    if ($value === null) {
        return '';
    }

    $value = (string) $value;
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $map = [
        'Ç' => 'C', 'ç' => 'c',
        'Ğ' => 'G', 'ğ' => 'g',
        'İ' => 'I', 'I' => 'I', 'ı' => 'i',
        'Ö' => 'O', 'ö' => 'o',
        'Ş' => 'S', 'ş' => 's',
        'Ü' => 'U', 'ü' => 'u',
        'Â' => 'A', 'â' => 'a',
        'Ê' => 'E', 'ê' => 'e',
        'Ô' => 'O', 'ô' => 'o'
    ];
    $value = strtr($value, $map);

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '', $value);
    return $value ?: '';
}

/**
 * Attempts to find a column header by keyword family.
 */
function bursmetre_find_column($headers, array $needles)
{
    if (!$headers) {
        return null;
    }

    $needle_slugs = array_filter(array_map('bursmetre_slug', $needles));

    foreach ($headers as $header) {
        $header_slug = bursmetre_slug($header);
        if ($header_slug === '') {
            continue;
        }
        foreach ($needle_slugs as $needle) {
            if ($needle !== '' && strpos($header_slug, $needle) !== false) {
                return $header;
            }
        }
    }

    return null;
}

/**
 * Safely pulls a cell from row by header.
 */
function bursmetre_get_value($row, $headers, $header)
{
    if ($header === null) {
        return '';
    }
    $index = array_search($header, $headers, true);
    if ($index === false || !isset($row[$index])) {
        return '';
    }
    return trim($row[$index]);
}

/**
 * Parses Turkish formatted amounts.
 */
function bursmetre_parse_amount($value)
{
    if (is_numeric($value)) {
        return (float) $value;
    }

    $value = (string) $value;
    $value = str_replace(["\u{00A0}", ' '], '', $value);
    $value = preg_replace('/[^\d,.\-]/', '', $value);

    $comma_count = substr_count($value, ',');
    $dot_count = substr_count($value, '.');
    $has_comma = $comma_count > 0;
    $has_dot = $dot_count > 0;

    if ($has_comma && $has_dot) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } elseif ($has_comma) {
        $value = str_replace(',', '.', $value);
    } elseif ($has_dot && $dot_count > 1) {
        $value = str_replace('.', '', $value);
    }

    $value = trim($value);
    if ($value === '' || $value === '.') {
        return 0.0;
    }

    return (float) $value;
}

/**
 * Parses integer-like values.
 */
function bursmetre_parse_int($value)
{
    if (is_numeric($value)) {
        return (int) $value;
    }
    $value = preg_replace('/[^\d\-]/', '', (string) $value);
    return $value === '' ? 0 : (int) $value;
}

/**
 * Currency formatting helper.
 */
function bursmetre_format_currency($amount)
{
    return '₺' . number_format((float) $amount, 0, ',', '.');
}

$burs_csv = bursmetre_fetch_csv($burs_id, $burs_gid);
$kamp_csv = bursmetre_fetch_csv($kamp_id, $kamp_gid);

list($burs_headers, $burs_rows) = bursmetre_csv_to_array($burs_csv);
list($kamp_headers, $kamp_rows) = bursmetre_csv_to_array($kamp_csv);

// Column discovery for burs sheet.
$col_index      = bursmetre_find_column($burs_headers, ['sira', 'index', 'no']);
$col_first_name = bursmetre_find_column($burs_headers, ['ad', 'isim', 'first']);
$col_last_name  = bursmetre_find_column($burs_headers, ['soyad', 'last']);
$col_full_name  = bursmetre_find_column($burs_headers, ['ad soyad', 'isim soyisim', 'name']);
$col_dept       = bursmetre_find_column($burs_headers, ['bolum', 'bölüm', 'department', 'dept']);
$col_one_time   = bursmetre_find_column($burs_headers, ['tek seferlik', 'tekseferlik']);
$col_recurring  = bursmetre_find_column($burs_headers, ['duzenli', 'düzenli', 'regular']);
$col_total      = bursmetre_find_column($burs_headers, ['toplam bagis', 'toplam bağış', 'toplam', 'bagis']);
$col_donor      = bursmetre_find_column($burs_headers, ['bagisci', 'bağışçı', 'donor', 'bagisci sayisi']);
$col_date       = bursmetre_find_column($burs_headers, ['tarih', 'date']);

// Column discovery for kamp sheet.
$col_k_name  = bursmetre_find_column($kamp_headers, ['kampanya açan', 'kampanya acan', 'ad soyad', 'organizer', 'campaign']);
$col_k_dept  = bursmetre_find_column($kamp_headers, ['bolum', 'bölüm', 'dept', 'department']);
$col_k_goal  = bursmetre_find_column($kamp_headers, ['hedef', 'goal', 'target']);
$col_k_link  = bursmetre_find_column($kamp_headers, ['link', 'url', 'bagis sayfasi', 'sayfa', 'column 2']);
$col_k_image = bursmetre_find_column($kamp_headers, ['profil', 'profile', 'resim', 'image', 'column 3', 'avatar']);
$col_k_amt   = bursmetre_find_column($kamp_headers, ['toplam', 'toplanan', 'bagis', 'bağış']);

$campaigns = [];
$total_donation = 0.0;
$total_donors = 0;

// Build participants from burs sheet.
if ($burs_rows) {
    foreach ($burs_rows as $row) {
        $first = bursmetre_get_value($row, $burs_headers, $col_first_name);
        $last  = bursmetre_get_value($row, $burs_headers, $col_last_name);
        $full  = trim($first . ' ' . $last);
        if ($full === '') {
            $full = bursmetre_get_value($row, $burs_headers, $col_full_name);
        }
        if ($full === '') {
            continue;
        }

        $key = bursmetre_slug($full);
        if ($key === '') {
            continue;
        }

        $department = bursmetre_get_value($row, $burs_headers, $col_dept);
        $one_time   = bursmetre_parse_amount(bursmetre_get_value($row, $burs_headers, $col_one_time));
        $recurring  = bursmetre_parse_amount(bursmetre_get_value($row, $burs_headers, $col_recurring));
        $total      = bursmetre_parse_amount(bursmetre_get_value($row, $burs_headers, $col_total));
        if ($total === 0.0) {
            $total = $one_time + $recurring;
        }
        $donors = bursmetre_parse_int(bursmetre_get_value($row, $burs_headers, $col_donor));

        $campaigns[$key] = [
            'name'       => $full,
            'department' => $department,
            'amount'     => $total,
            'one_time'   => $one_time,
            'recurring'  => $recurring,
            'donors'     => $donors,
            'goal'       => 0.0,
            'link'       => '',
            'image'      => '',
        ];

        $total_donation += $total;
        $total_donors   += $donors;

    }
}

// Enrich campaigns with metadata from kamp sheet.
if ($kamp_rows) {
    foreach ($kamp_rows as $row) {
        $name = bursmetre_get_value($row, $kamp_headers, $col_k_name);
        if ($name === '') {
            continue;
        }

        $key = bursmetre_slug($name);
        if ($key === '') {
            continue;
        }

        if (!isset($campaigns[$key])) {
            $campaigns[$key] = [
                'name'       => $name,
                'department' => '',
                'amount'     => 0.0,
                'one_time'   => 0.0,
                'recurring'  => 0.0,
                'donors'     => 0,
                'goal'       => 0.0,
                'link'       => '',
                'image'      => '',
            ];
        } else {
            // Use spelling from kamp sheet when available.
            $campaigns[$key]['name'] = $name;
        }

        $dept = bursmetre_get_value($row, $kamp_headers, $col_k_dept);
        if ($dept !== '') {
            $campaigns[$key]['department'] = $dept;
        }

        $campaign_goal = bursmetre_parse_amount(bursmetre_get_value($row, $kamp_headers, $col_k_goal));
        if ($campaign_goal > 0) {
            $campaigns[$key]['goal'] = $campaign_goal;
        }

        if ($col_k_amt) {
            $sheet_amount = bursmetre_parse_amount(bursmetre_get_value($row, $kamp_headers, $col_k_amt));
            if ($sheet_amount > 0 && $sheet_amount > $campaigns[$key]['amount']) {
                $campaigns[$key]['amount'] = $sheet_amount;
            }
        }

        $link = bursmetre_get_value($row, $kamp_headers, $col_k_link);
        if ($link !== '') {
            $campaigns[$key]['link'] = $link;
        }

        $image = bursmetre_get_value($row, $kamp_headers, $col_k_image);
        if ($image !== '') {
            $campaigns[$key]['image'] = $image;
        }
    }
}

// Aggregate departments after enrichment.
$dept_totals = [];
foreach ($campaigns as $campaign) {
    $dept = trim($campaign['department']);
    if ($dept === '') {
        continue;
    }
    if (!isset($dept_totals[$dept])) {
        $dept_totals[$dept] = 0.0;
    }
    $dept_totals[$dept] += $campaign['amount'];
}
arsort($dept_totals);

// Metrics.
$active_campaigns    = 0;
$completed_campaigns = 0;
foreach ($campaigns as $campaign) {
    if ($campaign['amount'] > 0) {
        $active_campaigns++;
    }
    if ($campaign['goal'] > 0 && $campaign['amount'] >= $campaign['goal']) {
        $completed_campaigns++;
    }
}

$progress = ($goal > 0)
    ? min(100, round(($total_donation / $goal) * 100, 1))
    : 0;

$students_per_burs = 38250;
$burs_students_collected = ($students_per_burs > 0)
    ? round($total_donation / $students_per_burs)
    : 0;
$students_formatted = function_exists('number_format_i18n')
    ? number_format_i18n($burs_students_collected)
    : number_format($burs_students_collected, 0, '', '.');

// Prepare chart payloads.
$dept_chart_totals = array_slice($dept_totals, 0, 20, true);
$dept_labels = array_keys($dept_chart_totals);
$dept_values = array_values($dept_chart_totals);
$dept_colors = [];
$palette = ['#f44336', '#ff9800', '#ffeb3b', '#4caf50', '#2196f3', '#673ab7', '#009688', '#9c27b0', '#3f51b5', '#ffc107', '#8bc34a', '#00bcd4'];
for ($i = 0; $i < count($dept_labels); $i++) {
    $dept_colors[] = $palette[$i % count($palette)];
}

$campaigns_sorted = array_filter($campaigns, function ($campaign) {
    return $campaign['amount'] > 0;
});
if (empty($campaigns_sorted)) {
    $campaigns_sorted = $campaigns;
}

uasort($campaigns_sorted, function ($a, $b) {
    return $b['amount'] <=> $a['amount'];
});

$rank_list = array_values($campaigns_sorted);
$top_campaigns = array_slice($rank_list, 0, 10);
$top_labels = array_map(function ($item) {
    return $item['name'];
}, $top_campaigns);
$top_values = array_map(function ($item) {
    return $item['amount'];
}, $top_campaigns);
$top_colors = [];
$top_palette = ['#ffcd56', '#ff6384', '#36a2eb', '#4bc0c0', '#9966ff', '#ff9f40', '#c9cbcf', '#ff6f91', '#845ef7', '#51cf66'];
for ($i = 0; $i < count($top_values); $i++) {
    $top_colors[] = $top_palette[$i % count($top_palette)];
}

// Ranking list uses full campaign list.
// Department race (same ordering as dept_totals).
$dept_race = [];
foreach ($dept_totals as $dept => $value) {
    $dept_race[] = [
        'department' => $dept,
        'amount'     => $value,
    ];
}

$hero_caption = $completed_campaigns > 0
    ? sprintf('%d kampanya hedefini tamamladı', $completed_campaigns)
    : sprintf('%d kampanya şu anda aktif', $active_campaigns);

$chart_dept_payload = wp_json_encode(
    [
        'labels' => $dept_labels,
        'values' => $dept_values,
        'colors' => $dept_colors,
    ],
    JSON_UNESCAPED_UNICODE
);

$chart_top_payload = wp_json_encode(
    [
        'labels' => $top_labels,
        'values' => $top_values,
        'colors' => $top_colors,
    ],
    JSON_UNESCAPED_UNICODE
);

$max_dept_amount = !empty($dept_race) ? max(array_column($dept_race, 'amount')) : 0.0;

?>

<div class="burs-dashboard">
    <section class="burs-hero">
        <div class="        <div class="burs-hero__body">
            <div class="burs-hero__meta">
                <span class="burs-hero__chip burs-hero__chip--meta">
                    <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 3l9 4v4c0 5.52-4.48 10-10 10S2 16.52 2 11V7zM4 8.2v2.8c0 4.41 3.59 8 8 8s8-3.59 8-8V8.2l-8-3.6zM12 9a3 3 0 0 1 2.82 4H15a2 2 0 0 0-2-2a2 2 0 0 0-1.87 1.25l-1.84-.78A3.99 3.99 0 0 1 12 9Z"/></svg>
                    <?php echo esc_html($hero_caption); ?>
                </span>
            </div>
            <h1>&#304;stanbul Maratonu ODT&#220;M&#304;ST Burs Kampanyalar&#305;</h1>
            <p>ODT&#220;'l&#252; &#246;&#287;renciler i&#231;in ko&#351;uyoruz.</p>
            <div class="burs-hero__sub">
                <span class="burs-hero__chip burs-hero__chip--students">
                    <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 3L1 9l11 6l9-4.91V17h2V9zM12 21l-8-4.5V11l8 4.5l8-4.5v5.5z"/></svg>
                    <span><strong><?php echo esc_html($students_formatted); ?></strong> &#214;&#287;rencinin Bursu Topland&#305;</span>
                </span>
            </div>
            <div class="burs-hero__progress">
                <div class="burs-progress">
                    <div class="burs-progress__inner" data-progress="<?php echo esc_attr($progress); ?>"></div>
                </div>
                <div class="burs-hero__totals">
                    <div>
                        <span class="burs-hero__label">Toplanan</span>
                        <strong><?php echo esc_html(bursmetre_format_currency($total_donation)); ?></strong>
                        <span class="burs-hero__divider">/</span>
                        <span><?php echo esc_html(bursmetre_format_currency($goal)); ?></span>
                    </div>
                    <div class="burs-hero__percentage">%<?php echo esc_html($progress); ?></div>
                </div>
            </div>
        </div>
    </section>

    <section class="burs-stats">
        <article class="burs-stat burs-stat--primary">
            <div class="burs-stat__icon">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 1a9 9 0 0 0-9 9h2a7 7 0 1 1 7 7v2.07A9.001 9.001 0 0 0 12 1m-.5 5v2.09A3 3 0 0 0 9 11c0 1.31.84 2.42 2 2.83V17h1v-3.17A3 3 0 0 0 15 11h-1a2 2 0 0 1-2 2a2 2 0 0 1 0-4h2V7h-1.5V5z"/></svg>
            </div>
            <h3>Toplam Bağış</h3>
            <p><?php echo esc_html(bursmetre_format_currency($total_donation)); ?></p>
        </article>
        <article class="burs-stat burs-stat--focus">
            <div class="burs-stat__icon">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 8a4 4 0 1 0 4 4h2a6 6 0 1 1-6-6z"/><path fill="currentColor" d="M22 13h-2.05A8.005 8.005 0 0 1 11 4.05V2h2V0h-2a1 1 0 0 0-1 1v2.05A8.005 8.005 0 0 0 4.05 11H2v2h2.05A8.005 8.005 0 0 0 11 19.95V22h2v-2.05A8.005 8.005 0 0 0 19.95 13H22z"/></svg>
            </div>
            <h3>Aktif Kampanya</h3>
            <p><?php echo esc_html($active_campaigns); ?></p>
        </article>
        <article class="burs-stat burs-stat--info">
            <div class="burs-stat__icon">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3s1.34 3 3 3M8 11c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5S5 6.34 5 8s1.34 3 3 3m0 2c-2.33 0-7 1.17-7 3.5V20h14v-3.5c0-2.33-4.67-3.5-7-3.5m8 0c-.29 0-.62.02-.97.05c1.16.84 1.97 1.97 1.97 3.45V20h6v-3.5c0-2.33-4.67-3.5-7-3.5"/></svg>
            </div>
            <h3>Toplam Bağışçı</h3>
            <p><?php echo esc_html(number_format((int) $total_donors, 0, ',', '.')); ?></p>
        </article>
    </section>

    <section class="burs-grid">
        <article class="burs-panel">
            <header class="burs-panel__header">
                <h2>Bölüm Bağış Dağılımı</h2>
            </header>
            <canvas id="deptChart" data-chart='<?php echo esc_attr($chart_dept_payload); ?>'></canvas>
        </article>
        <article class="burs-panel">
            <header class="burs-panel__header">
                <h2>En Yüksek Bağış Toplayan Kampanyalar</h2>
            </header>
            <canvas id="topCampaignsChart" data-chart='<?php echo esc_attr($chart_top_payload); ?>'></canvas>
        </article>
    </section>

    <section class="burs-panel burs-panel--ranking">
        <header class="burs-panel__header">
            <div class="burs-panel__title">
                <span class="burs-icon burs-icon--trophy"></span>
                <h2>Kampanya Sıralaması</h2>
            </div>
        </header>
        <div class="burs-ranking">
            <?php
            $position = 1;
            foreach ($rank_list as $campaign) :
                $goal = $campaign['goal'] > 0 ? $campaign['goal'] : max(1, $campaign['amount']);
                $pct  = $goal > 0 ? min(100, round(($campaign['amount'] / $goal) * 100, 1)) : 0;
                $link = $campaign['link'] ? esc_url($campaign['link']) : '';
                $image = $campaign['image'] ? esc_url($campaign['image']) : '';
                $department = trim($campaign['department']);
                ?>
                <article class="burs-ranking__item">
                    <div class="burs-ranking__order burs-ranking__order--<?php echo esc_attr($position <= 3 ? $position : 'default'); ?>">
                        <span><?php echo esc_html($position); ?></span>
                    </div>
                    <?php if ($image) : ?>
                        <div class="burs-ranking__avatar">
                            <img src="<?php echo $image; ?>" alt="<?php echo esc_attr($campaign['name']); ?>" loading="lazy" />
                        </div>
                    <?php else : ?>
                        <?php
                        $initial_char = function_exists('mb_substr')
                            ? mb_substr($campaign['name'], 0, 1, 'UTF-8')
                            : substr($campaign['name'], 0, 1);
                        $initial_char = function_exists('mb_strtoupper')
                            ? mb_strtoupper($initial_char, 'UTF-8')
                            : strtoupper($initial_char);
                        ?>
                        <div class="burs-ranking__avatar burs-ranking__avatar--placeholder">
                            <span><?php echo esc_html($initial_char); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="burs-ranking__body">
                        <div class="burs-ranking__top">
                            <h3><?php echo esc_html($campaign['name']); ?></h3>
                            <?php if ($department !== '') : ?>
                                <span class="burs-chip"><?php echo esc_html($department); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="burs-ranking__meta">
                            <div class="burs-ranking__amount">
                                <strong><?php echo esc_html(bursmetre_format_currency($campaign['amount'])); ?></strong>
                                <span>%<?php echo esc_html($pct); ?><?php echo $campaign['goal'] > 0 ? ' / ' . esc_html(bursmetre_format_currency($campaign['goal'])) : ''; ?></span>
                            </div>
                            <?php if ($link) : ?>
                                <a class="burs-ranking__link" href="<?php echo $link; ?>" target="_blank" rel="noopener">
                                    <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M14 3l2.29 2.29l-8.8 8.8l1.42 1.42l8.8-8.8L21 10V3zM5 5h5V3H3v7h2z"/></svg>
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="burs-ranking__extra">
                            <?php if ($campaign['donors'] > 0) : ?>
                                <span class="burs-badge burs-badge--donors">
                                    <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3s1.34 3 3 3m-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5S5 6.34 5 8s1.34 3 3 3m0 2c-2.33 0-7 1.17-7 3.5V20h14v-3.5c0-2.33-4.67-3.5-7-3.5m8 0c-.29 0-.62.02-.97.05c1.16.84 1.97 1.97 1.97 3.45V20h6v-3.5c0-2.33-4.67-3.5-7-3.5"/></svg>
                                    <?php echo esc_html($campaign['donors']); ?> bağışçı
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="burs-progress burs-progress--ranking">
                            <div class="burs-progress__inner" data-progress="<?php echo esc_attr($pct); ?>"></div>
                        </div>
                    </div>
                </article>
                <?php
                $position++;
            endforeach;
            ?>
        </div>
    </section>

    <section class="burs-panel burs-panel--departments">
        <header class="burs-panel__header">
            <div class="burs-panel__title">
                <span class="burs-icon burs-icon--medal"></span>
                <h2>Bölümler Yarışıyor</h2>
            </div>
        </header>
        <div class="burs-departments">
            <?php
            $rank = 1;
            foreach ($dept_race as $row) :
                $dept_amount = $row['amount'];
                $relative = ($max_dept_amount > 0) ? round(($dept_amount / $max_dept_amount) * 100, 1) : 0;
                ?>
                <article class="burs-departments__item">
                    <div class="burs-departments__header">
                        <div class="burs-departments__title">
                            <span class="burs-departments__badge">#<?php echo esc_html($rank); ?></span>
                            <span><?php echo esc_html($row['department']); ?></span>
                        </div>
                        <div class="burs-departments__amount">
                            <?php echo esc_html(bursmetre_format_currency($dept_amount)); ?>
                        </div>
                    </div>
                    <div class="burs-progress burs-progress--departments">
                        <div class="burs-progress__inner" data-progress="<?php echo esc_attr($relative); ?>"></div>
                    </div>
                </article>
                <?php
                $rank++;
            endforeach;
            ?>
        </div>
    </section>
</div>
