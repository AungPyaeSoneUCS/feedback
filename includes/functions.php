<?php
require_once __DIR__ . '/../config/base_url.php';

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'mm'])) {
    $_SESSION['lang'] = $_GET['lang'];
}

if (!isset($_SESSION['lang']))
    $_SESSION['lang'] = 'en';

$langFile = __DIR__ . '/../lang/' . $_SESSION['lang'] . '.php';
if (file_exists($langFile)) {
    require_once $langFile;
} else {
    require_once __DIR__ . '/../lang/en.php';
}
if (!isset($LANG) || !is_array($LANG))
    $LANG = [];

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function clean($val): string
{
    return trim(strip_tags($val));
}

// ─── Myanmar Number Conversion ──────────────────────────────────────────────
function convertToMyanmarNumber($number): string
{
    $myanmarDigits = ['၀', '၁', '၂', '၃', '၄', '၅', '၆', '၇', '၈', '၉'];
    return str_replace(range(0, 9), $myanmarDigits, (string) $number);
}

function displayQuestionNumber($number, $language = 'en'): string
{
    if ($language === 'mm') {
        return convertToMyanmarNumber($number) . '။';
    }
    return $number . '.';
}

function formatDate(?string $date): string
{
    if (!$date || $date === '0000-00-00' || $date === '0000-00-00 00:00:00')
        return '—';
    return date('M d, Y', strtotime($date));
}

function formatDateTime(?string $date): string
{
    if (!$date || $date === '0000-00-00' || $date === '0000-00-00 00:00:00')
        return '—';
    return date('M d, Y h:i A', strtotime($date));
}

function formatDateTimeShort(?string $date): string
{
    if (!$date || $date === '0000-00-00' || $date === '0000-00-00 00:00:00')
        return '—';
    return date('M d, h:i A', strtotime($date));
}

function formatDateTimeForInput(?string $date): string
{
    if (!$date || $date === '0000-00-00' || $date === '0000-00-00 00:00:00')
        return '';
    return date('Y-m-d\TH:i', strtotime($date));
}

function getFeedbackStatus(string $start, string $end): string
{
    global $LANG;
    $now = time();
    $startTs = strtotime($start);
    $endTs = strtotime($end);
    if ($startTs === false || $endTs === false) {
        return $LANG['upcoming'] ?? 'Upcoming';
    }
    if ($now < $startTs)
        return $LANG['upcoming'] ?? 'Upcoming';
    if ($now > $endTs)
        return $LANG['expired'] ?? 'Expired';
    return $LANG['active'] ?? 'Active';
}

function calculateFormStatus(string $start, string $end): string
{
    global $LANG;
    $now = time();
    $startTs = strtotime($start);
    $endTs = strtotime($end);
    if ($startTs === false || $endTs === false) {
        return $LANG['upcoming'] ?? 'Upcoming';
    }
    if ($now < $startTs)
        return $LANG['upcoming'] ?? 'Upcoming';
    if ($now > $endTs)
        return $LANG['expired'] ?? 'Expired';
    return $LANG['active'] ?? 'Active';
}

function updateAllFeedbackStatuses(&$conn): void
{
    $conn->query("UPDATE feedback_forms SET status = CASE
        WHEN NOW() < start_date THEN 'Upcoming'
        WHEN NOW() >= start_date AND NOW() <= end_date THEN 'Active'
        WHEN NOW() > end_date THEN 'Expired'
        ELSE status
    END");
}

function getTimeRemaining(string $end): string
{
    global $LANG;
    $now = new DateTime();
    $endTime = new DateTime($end);
    if ($now >= $endTime)
        return '';
    $diff = $now->diff($endTime);
    $suffix = $LANG['remaining_suffix'] ?? 'remaining';
    if ($diff->days > 0)
        return $diff->days . 'd ' . $diff->h . 'h ' . $suffix;
    if ($diff->h > 0)
        return $diff->h . 'h ' . $diff->i . 'm ' . $suffix;
    return $diff->i . 'm ' . $suffix;
}

function getTimeUntilStart(string $start): string
{
    global $LANG;
    $now = new DateTime();
    $startTime = new DateTime($start);
    if ($now >= $startTime)
        return '';
    $diff = $now->diff($startTime);
    $suffix = $LANG['until_opens'] ?? 'until opens';
    if ($diff->days > 0)
        return $diff->days . 'd ' . $diff->h . 'h ' . $suffix;
    if ($diff->h > 0)
        return $diff->h . 'h ' . $diff->i . 'm ' . $suffix;
    return $diff->i . 'm ' . $suffix;
}

function avatarInitials(string $name): string
{
    $parts = array_filter(explode(' ', trim($name)));
    $initials = '';
    foreach ($parts as $p) {
        $initials .= strtoupper($p[0]);
        if (strlen($initials) >= 2)
            break;
    }
    return $initials ?: 'U';
}

function paginate(int $total, int $perPage, int $current): array
{
    $totalPages = max(1, (int) ceil($total / $perPage));
    $current = max(1, min($current, $totalPages));
    return [
        'total' => $total,
        'per_page' => $perPage,
        'current' => $current,
        'total_pages' => $totalPages,
        'offset' => ($current - 1) * $perPage,
    ];
}

function paginationLinks(array $pg, string $baseUrl, int $perPage = 10): string
{
    global $LANG;
    if ($pg['total'] === 0)
        return '';
    $cur = $pg['current'];
    $total = $pg['total_pages'];
    $showing = $LANG['pagination_showing'] ?? 'Showing';
    $of = $LANG['pagination_of'] ?? 'of';

    // Build URL helper: preserves all existing params, replaces page/per_page
    $parsedUrl = parse_url($baseUrl);
    $existingParams = [];
    if (!empty($parsedUrl['query'])) {
        parse_str($parsedUrl['query'], $existingParams);
    }
    $path = $parsedUrl['path'] ?? '';

    $buildUrl = function (int $page) use ($path, $existingParams, $perPage) {
        $params = $existingParams;
        $params['page'] = $page;
        $params['per_page'] = $perPage;
        return $path . '?' . http_build_query($params);
    };

    // Build page numbers: first, last, current±1, with ellipsis
    $pages = [];
    $pages[] = 1;
    for ($i = max(2, $cur - 1); $i <= min($total - 1, $cur + 1); $i++) {
        $pages[] = $i;
    }
    if ($total > 1) {
        $pages[] = $total;
    }
    $pages = array_unique($pages);
    sort($pages);

    // Build page links with ellipsis
    $pageLinks = '';
    $prevPage = 0;
    foreach ($pages as $p) {
        if ($prevPage > 0 && $p - $prevPage > 1) {
            $pageLinks .= '<span class="px-3 py-2 text-sm text-slate-400 select-none">...</span>';
        }
        if ($p === $cur) {
            $pageLinks .= '<span class="px-3.5 py-2 rounded-lg bg-indigo-600 text-white text-sm font-bold shadow-sm">' . $p . '</span>';
        } else {
            $pageLinks .= '<a href="' . $buildUrl($p) . '" class="px-3.5 py-2 rounded-lg border border-slate-200 text-sm text-slate-600 font-medium hover:bg-slate-100 hover:border-slate-300 transition-all duration-150">' . $p . '</a>';
        }
        $prevPage = $p;
    }

    // Per-page selector
    $ppOptions = '';
    foreach ([10, 25, 50, 100] as $pp) {
        $ppOptions .= '<option value="' . $pp . '"' . ($pp == $perPage ? ' selected' : '') . '>' . $pp . '</option>';
    }

    $start = ($cur - 1) * $perPage + 1;
    $end = min($cur * $perPage, $pg['total']);

    $html = '<nav class="flex flex-col sm:flex-row items-center justify-between gap-4 mt-5 pt-5 border-t border-slate-100">';

    // Left: showing text + per-page selector
    $html .= '<div class="flex flex-wrap items-center gap-3 text-sm text-slate-500">';
    $html .= '<span>' . $showing . ' <span class="font-semibold text-slate-700">' . $start . '–' . $end . '</span> ' . $of . ' <span class="font-semibold text-slate-700">' . $pg['total'] . '</span></span>';
    $html .= '<span class="text-slate-300">|</span>';
    $html .= '<span class="inline-flex items-center gap-1.5">';
    $html .= '<span>Show</span>';
    $html .= '<select onchange="changePerPage(this.value, \'' . htmlspecialchars(addcslashes($path, "'"), ENT_QUOTES) . '\', ' . $perPage . ')" class="border border-slate-200 rounded-lg px-2 py-1 text-sm font-semibold text-slate-700 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer transition-all duration-150">';
    $html .= $ppOptions;
    $html .= '</select>';
    $html .= '<span>per page</span>';
    $html .= '</span>';
    $html .= '</div>';

    // Center: page numbers + prev/next
    $html .= '<div class="flex items-center gap-1.5">';

    // Prev button
    if ($cur > 1) {
        $html .= '<a href="' . $buildUrl($cur - 1) . '" class="px-3.5 py-2 rounded-lg border border-slate-200 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:border-slate-300 transition-all duration-150 inline-flex items-center gap-1">';
        $html .= '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>';
        $html .= 'Prev</a>';
    } else {
        $html .= '<span class="px-3.5 py-2 rounded-lg border border-slate-100 text-sm font-medium text-slate-300 cursor-not-allowed inline-flex items-center gap-1">';
        $html .= '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>';
        $html .= 'Prev</span>';
    }

    $html .= $pageLinks;

    // Next button
    if ($cur < $total) {
        $html .= '<a href="' . $buildUrl($cur + 1) . '" class="px-3.5 py-2 rounded-lg border border-slate-200 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:border-slate-300 transition-all duration-150 inline-flex items-center gap-1">';
        $html .= 'Next<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>';
        $html .= '</a>';
    } else {
        $html .= '<span class="px-3.5 py-2 rounded-lg border border-slate-100 text-sm font-medium text-slate-300 cursor-not-allowed inline-flex items-center gap-1">';
        $html .= 'Next<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>';
        $html .= '</span>';
    }

    $html .= '</div></nav>';

    // Per-page change script (inline, only output once)
    $html .= '<script>
if(typeof changePerPage!=="function"){
    function changePerPage(val,path,currentPP){
        if(val==currentPP)return;
        var u=new URL(window.location.href);
        u.searchParams.set("per_page",val);
        u.searchParams.delete("page");
        window.location.href=u.href;
    }
}
</script>';

    return $html;
}

function badgeRole(string $role): string
{
    global $LANG;
    return match ($role) {
        'admin' => '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">' . e($LANG['admin_role'] ?? 'Admin') . '</span>',
        'teacher' => '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-cyan-100 text-cyan-800">' . e($LANG['teacher_role'] ?? 'Teacher') . '</span>',
        'student' => '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">' . e($LANG['student_role'] ?? 'Student') . '</span>',
        default => '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-700">' . e($role) . '</span>',
    };
}

function badgeStatus(string $status): string
{
    global $LANG;
    $normalized = strtolower($status);
    return match ($normalized) {
        'active' => '<span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800"><span class="w-1.5 h-1.5 rounded-full bg-green-500 inline-block"></span>' . e($LANG['active'] ?? 'Active') . '</span>',
        'upcoming' => '<span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800"><span class="w-1.5 h-1.5 rounded-full bg-blue-500 inline-block"></span>' . e($LANG['upcoming'] ?? 'Upcoming') . '</span>',
        'expired' => '<span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600"><span class="w-1.5 h-1.5 rounded-full bg-slate-400 inline-block"></span>' . e($LANG['expired'] ?? 'Expired') . '</span>',
        default => '<span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600"><span class="w-1.5 h-1.5 rounded-full bg-slate-400 inline-block"></span>' . e($status) . '</span>',
    };
}

function iconSvg(string $name, string $class = 'w-5 h-5'): string
{
    $paths = [
        'home' => 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
        'users' => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
        'building' => 'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z',
        'academic' => 'M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5',
        'book' => 'M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25',
        'grid' => 'M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z',
        'clipboard' => 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z',
        'chart' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
        'user' => 'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z',
        'logout' => 'M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75',
        'plus' => 'M12 4.5v15m7.5-7.5h-15',
        'edit' => 'M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10',
        'trash' => 'M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0',
        'eye' => 'M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
        'search' => 'M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 15.803 7.5 7.5 0 0015.803 15.803z',
        'check' => 'M4.5 12.75l6 6 9-13.5',
        'x' => 'M6 18L18 6M6 6l12 12',
        'link' => 'M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244',
        'star' => 'M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z',
        'question' => 'M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z',
        'document' => 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z',
        'report' => 'M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 01-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0112 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m7.5-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125',
        'shield' => 'M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z',
        'office' => 'M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21',
        'history' => 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        'beaker' => 'M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 1-6.23-.693L4.2 15.3m15.6 0-1.57 6.28m-12.46-6.28 1.57 6.28M12 12.75a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Z',
        'copy' => 'M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75',
    ];
    $d = $paths[$name] ?? '';
    return "<svg xmlns=\"http://www.w3.org/2000/svg\" fill=\"none\" viewBox=\"0 0 24 24\" stroke-width=\"1.5\" stroke=\"currentColor\" class=\"{$class}\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"{$d}\"/></svg>";
}

/**
 * Calculate most-selected option indices for a survey question.
 *
 * @param array $voteCounts  Associative array keyed by option index => vote count
 *                            e.g. [0 => 5, 1 => 12, 2 => 12]
 * @return array{indices: array<int>, max_votes: int, total: int}
 *   - indices:      All option indices tied for the highest count (sorted ascending).
 *                   Empty array when $voteCounts is empty or all values are zero.
 *   - max_votes:    The highest vote count found.
 *   - total:        Sum of all votes.
 */
function getMostSelectedSurveyOptions(array $voteCounts): array
{
    if (empty($voteCounts)) {
        return ['indices' => [], 'max_votes' => 0, 'total' => 0];
    }

    $total = array_sum($voteCounts);
    $maxVotes = max($voteCounts);

    if ($maxVotes <= 0) {
        return ['indices' => [], 'max_votes' => 0, 'total' => $total];
    }

    // Collect ALL indices that share the max count
    $indices = [];
    foreach ($voteCounts as $idx => $count) {
        if ($count === $maxVotes) {
            $indices[] = (int) $idx;
        }
    }
    sort($indices);

    return ['indices' => $indices, 'max_votes' => $maxVotes, 'total' => $total];
}

/** Fixed application-level Likert options, ordered from 5 down to 1. */
function normalizeSurveyOptions(array|string|null $options): array
{
    global $LANG;
    return [
        ['value' => 5, 'label' => $LANG['likert_strongly_agree'] ?? 'Strongly Agree'],
        ['value' => 4, 'label' => $LANG['likert_agree'] ?? 'Agree'],
        ['value' => 3, 'label' => $LANG['likert_neutral'] ?? 'Neutral'],
        ['value' => 2, 'label' => $LANG['likert_disagree'] ?? 'Disagree'],
        ['value' => 1, 'label' => $LANG['likert_strongly_disagree'] ?? 'Strongly Disagree'],
    ];
}

/**
 * Load the questions and their Survey Group metadata for one Question Set.
 * Starting from feedback_questions ensures empty or unrelated groups are never
 * rendered as feedback sections.
 */
function getSurveyQuestionsForSet(mysqli $conn, int $questionSetId): array
{
    if ($questionSetId < 1)
        return [];

    $stmt = $conn->prepare(
        "SELECT fq.*,
                sg.id AS group_id,
                sg.group_code,
                sg.group_name_en,
                sg.group_name_mm,
                sg.instruction_en,
                sg.instruction_mm
         FROM feedback_questions fq
         JOIN survey_groups sg
           ON sg.id = fq.survey_group_id
          AND sg.question_set_id = fq.question_set_id
         WHERE fq.question_set_id = ?
         ORDER BY sg.id, LENGTH(fq.question_code), fq.question_code, fq.id"
    );
    $stmt->bind_param('i', $questionSetId);
    $stmt->execute();
    $questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $questions;
}

/** Return ratings only for Survey Groups containing questions in this set. */
function getSurveyGroupRatings(mysqli $conn, int $formId, int $questionSetId): array
{
    if ($formId < 1 || $questionSetId < 1)
        return [];

    $stmt = $conn->prepare(
        "SELECT sg.id AS group_id,
                sg.group_name_en,
                sg.group_name_mm,
                COUNT(DISTINCT fq.id) AS question_count,
                ROUND(AVG(CASE WHEN fs.form_id=? THEN fsa.rating END), 2) AS average
         FROM survey_groups sg
         JOIN feedback_questions fq
           ON fq.survey_group_id = sg.id
          AND fq.question_set_id = sg.question_set_id
         LEFT JOIN feedback_survey_answers fsa ON fsa.question_id = fq.id
         LEFT JOIN feedback_submissions fs ON fs.id = fsa.submission_id
         WHERE sg.question_set_id = ?
         GROUP BY sg.id, sg.group_name_en, sg.group_name_mm
         ORDER BY sg.id"
    );
    $stmt->bind_param('ii', $formId, $questionSetId);
    $stmt->execute();
    $groups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $groups;
}

function surveyCategoryCounts(array $options, array $optionCounts): array
{
    $counts = [];
    foreach (normalizeSurveyOptions($options) as $option) {
        $counts[$option['label']] = (int) ($optionCounts[$option['value']] ?? 0);
    }
    return $counts;
}

function surveyCategoryPercentages(array $categoryCounts): array
{
    $total = array_sum($categoryCounts);
    $result = [];
    foreach (normalizeSurveyOptions(null) as $option)
        $result[$option['label']] = $total ? round(($categoryCounts[$option['label']] ?? 0) * 100 / $total, 1) : 0.0;
    return $result;
}

/** Student-style performance grade for an already-calculated overall percentage. */
function defaultPerformanceGrades(): array
{
    return [
        ['excellent', 90.00, 100.00, 'performance_recommendation_excellent'],
        ['good', 80, 89, 'performance_recommendation_good'],
        ['satisfactory', 70, 79, 'performance_recommendation_satisfactory'],
        ['needs_improvement', 60, 69, 'performance_recommendation_needs_improvement'],
        ['unsatisfactory', 0, 59, 'performance_recommendation_unsatisfactory'],
    ];
}

function ensurePerformanceGradeSettings(mysqli $conn, int $academicYearId): void
{
    global $LANG;
    if ($academicYearId < 1) return;
    $stmt = $conn->prepare("INSERT IGNORE INTO performance_grade_settings
        (academic_year_id, grade_code, min_score, max_score, recommendation_key, recommendation)
        VALUES (?, ?, ?, ?, ?, ?)");
    foreach (defaultPerformanceGrades() as [$code, $min, $max, $recommendationKey]) {
        $recommendation = $LANG[$recommendationKey] ?? '';
        $stmt->bind_param('isddss', $academicYearId, $code, $min, $max, $recommendationKey, $recommendation);
        $stmt->execute();
    }
    $stmt->close();
}

function performanceGradeFromPercentage(mysqli $conn, float $percentage, int $academicYearId): array
{
    global $LANG;

    ensurePerformanceGradeSettings($conn, $academicYearId);
    $stmt = $conn->prepare("SELECT grade_code, recommendation_key, recommendation FROM performance_grade_settings
        WHERE academic_year_id = ? AND min_score <= ?
        ORDER BY min_score DESC LIMIT 1");
    $stmt->bind_param('id', $academicYearId, $percentage);
    $stmt->execute();
    $setting = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $key = $setting['grade_code'] ?? 'unsatisfactory';
    $recommendationKey = $setting['recommendation_key'] ?? ('performance_recommendation_' . $key);
    $recommendation = trim((string) ($setting['recommendation'] ?? ''));
    if ($recommendation === '')
        $recommendation = $LANG[$recommendationKey] ?? '';

    if ($key === 'excellent') {
        $color = 'emerald';
        $icon = '🏆';
    } elseif ($key === 'good') {
        $color = 'blue';
        $icon = '⭐';
    } elseif ($key === 'satisfactory') {
        $color = 'cyan';
        $icon = '👍';
    } elseif ($key === 'needs_improvement') {
        $color = 'amber';
        $icon = '⚠️';
    } else {
        $color = 'red';
        $icon = '👎';
    }

    $label = $LANG['performance_grade_' . $key] ?? ucwords(str_replace('_', ' ', $key));

    return [
        'key' => $key,
        'label' => $label,
        'color' => $color,
        'icon' => $icon,
        'recommendation_key' => $recommendationKey,
        'recommendation' => $recommendation,
    ];
}

function moduleBadge(string $module): string
{
    global $LANG;
    return match ($module) {
        'teaching_quality' => '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-cyan-100 text-cyan-800">📚 ' . e($LANG['academic_feedback'] ?? 'Teaching Quality') . '</span>',
        'student_support_services' => '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-purple-100 text-purple-800">🛡️ ' . e($LANG['student_affairs_section'] ?? 'Student Support Services') . '</span>',
        'learning_environment' => '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-orange-100 text-orange-800">🏢 ' . e($LANG['administration_section'] ?? 'Learning Environment') . '</span>',
        default => '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-700">' . e($module) . '</span>',
    };
}

function semesterToRoman($value): string
{
    $n = (int) $value;
    if ($n < 1 || $n > 20)
        return (string) $value;
    $ones = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX'];
    $tens = ['', 'X', 'XX'];
    $result = $tens[(int) ($n / 10)] . $ones[$n % 10];
    return 'Semester ' . $result;
}

function getStudentAcademicYearIds($conn, int $studentId): array
{
    if (!$studentId)
        return [];
    $stmt = $conn->prepare("SELECT DISTINCT s.academic_year_id FROM section_assignments sa JOIN sections s ON sa.section_id = s.id WHERE sa.student_id = ? AND s.academic_year_id IS NOT NULL");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $ids = [];
    while ($row = $result->fetch_assoc()) {
        $ids[] = (int) $row['academic_year_id'];
    }
    $stmt->close();
    return $ids;
}

function getStudentSemesterIds($conn, int $studentId): array
{
    if (!$studentId)
        return [];
    $stmt = $conn->prepare("SELECT DISTINCT s.semester_id FROM section_assignments sa JOIN sections s ON sa.section_id = s.id WHERE sa.student_id = ? AND s.semester_id IS NOT NULL");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $ids = [];
    while ($row = $result->fetch_assoc()) {
        $ids[] = (int) $row['semester_id'];
    }
    $stmt->close();
    return $ids;
}


/**
 * Check whether a Question Set's questions/groups may still be modified.
 *
 * Synchronisation (add / edit / delete questions or groups) is ONLY allowed
 * when EVERY feedback form that references this question set satisfies BOTH:
 *   1.  NOW() < start_date   (the form is still Upcoming)
 *   2.  submission_count = 0  (no student has submitted yet)
 *
 * @return array{allowed: bool, reason: string}
 *   reason is empty when allowed; otherwise 'active_or_expired_form' or 'has_submissions'.
 */
function canSyncQuestionSet(mysqli $conn, int $questionSetId): array
{
    if ($questionSetId < 1)
        return ['allowed' => true, 'reason' => ''];

    $stmt = $conn->prepare("
        SELECT ff.id,
               ff.start_date,
               (SELECT COUNT(*)
                FROM feedback_submissions fs
                WHERE fs.form_id = ff.id) AS submission_count
        FROM feedback_forms ff
        WHERE ff.question_set_id = ?
    ");
    $stmt->bind_param('i', $questionSetId);
    $stmt->execute();
    $forms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $now = time();
    foreach ($forms as $form) {
        $startTs = strtotime($form['start_date']);

        // Block if form is Active or Expired (NOW >= start_date)
        if ($startTs !== false && $now >= $startTs) {
            return ['allowed' => false, 'reason' => 'active_or_expired_form'];
        }

        // Block if form already has student submissions
        if ((int) $form['submission_count'] > 0) {
            return ['allowed' => false, 'reason' => 'has_submissions'];
        }
    }

    return ['allowed' => true, 'reason' => ''];
}
