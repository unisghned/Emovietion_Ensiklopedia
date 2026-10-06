<?php
// Konfigurasi Database Lokal SQLite (Watchlist & Ulasan Lokal)
$dbFile = __DIR__ . '/kleonflix_pro.db';
try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS watchlist (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        movie_id INTEGER,
        title TEXT,
        poster TEXT,
        vote_average REAL,
        release_date TEXT,
        added_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS reviews (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        movie_id INTEGER,
        username TEXT,
        rating INTEGER,
        comment TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS search_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        keyword TEXT,
        searched_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

// Konfigurasi API TMDB[cite: 1]
$apiKey = 'ad44db1973b13df06624224276c5d8e7'; 
$baseUrl = 'https://api.themoviedb.org/3';

function callTMDB($endpoint, $params = []) {
    global $baseUrl, $apiKey;
    $params['api_key'] = $apiKey;
    $params['language'] = 'en-US';
    
    $url = $baseUrl . $endpoint . '?' . http_build_query($params);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Tangkap Aksi & Parameter[cite: 1]
$action = isset($_GET['action']) ? $_GET['action'] : 'home';
$type = isset($_GET['type']) && in_array($_GET['type'], ['movie', 'tv', 'person']) ? $_GET['type'] : 'movie';
$searchQuery = isset($_GET['q']) ? trim($_GET['q']) : '';
$selectedGenre = isset($_GET['genre']) ? $_GET['genre'] : '';
$genreName = isset($_GET['genre_name']) ? $_GET['genre_name'] : '';
$sortBy = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'popularity.desc';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$showAllCast = isset($_GET['show_all_cast']) && $_GET['show_all_cast'] == '1';

// Proses Form Handling[cite: 1]
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_watchlist'])) {
        $mId = intval($_POST['movie_id']);
        $mTitle = $_POST['title'];
        $mPoster = $_POST['poster'];
        $mVote = floatval($_POST['vote_average']);
        $mDate = $_POST['release_date'];
        
        $stmt = $pdo->prepare("SELECT id FROM watchlist WHERE movie_id = ?");
        $stmt->execute([$mId]);
        if (!$stmt->fetch()) {
            $ins = $pdo->prepare("INSERT INTO watchlist (movie_id, title, poster, vote_average, release_date) VALUES (?, ?, ?, ?, ?)");
            $ins->execute([$mId, $mTitle, $mPoster, $mVote, $mDate]);
        }
        header("Location: ?detail_id=" . $mId . "&type=" . $type);
        exit;
    }
    
    if (isset($_POST['remove_watchlist'])) {
        $wId = intval($_POST['watchlist_id']);
        $del = $pdo->prepare("DELETE FROM watchlist WHERE id = ?");
        $del->execute([$wId]);
        header("Location: ?action=watchlist");
        exit;
    }

    if (isset($_POST['submit_review'])) {
        $mId = intval($_POST['movie_id']);
        $user = trim($_POST['username']) ?: 'Anonim';
        $rat = intval($_POST['rating']);
        $comm = trim($_POST['comment']);
        
        $rev = $pdo->prepare("INSERT INTO reviews (movie_id, username, rating, comment) VALUES (?, ?, ?, ?)");
        $rev->execute([$mId, $user, $rat, $comm]);
        header("Location: ?detail_id=" . $mId . "&type=" . $type);
        exit;
    }
}

// Ambil Data Genre[cite: 1]
$genresResponse = callTMDB("/genre/{$type}/list");
$genresList = $genresResponse['genres'] ?? [];

$items = [];
$totalPages = 1;
$pageTitle = "🔥 Trending Global Hari Ini";

$heroMovies = [];
$latestMovies = [];
$topTenMovies = [];

if ($action === 'home' && $type === 'movie') {
    $trendingData = callTMDB("/trending/movie/day");
    $trendingResults = $trendingData['results'] ?? [];
    
    $heroMovies = array_slice($trendingResults, 0, 8);
    $latestData = callTMDB("/movie/now_playing", ['page' => 1]);
    $latestMovies = $latestData['results'] ?? [];
    $topTenMovies = array_slice($trendingResults, 0, 10);
}

if ($action === 'search' && !empty($searchQuery)) {
    $hist = $pdo->prepare("INSERT INTO search_history (keyword) VALUES (?)");
    $hist->execute([$searchQuery]);

    $data = callTMDB("/search/{$type}", ['query' => $searchQuery, 'page' => $page]);
    $items = $data['results'] ?? [];
    $totalPages = $data['total_pages'] ?? 1;
    $pageTitle = "Hasil Pencarian: \"" . htmlspecialchars($searchQuery) . "\"";
} elseif ($action === 'genre' && !empty($selectedGenre)) {
    $data = callTMDB("/discover/{$type}", ['with_genres' => $selectedGenre, 'sort_by' => $sortBy, 'page' => $page]);
    $items = $data['results'] ?? [];
    $totalPages = $data['total_pages'] ?? 1;
    $pageTitle = "Kategori: " . htmlspecialchars($genreName);
} elseif ($action === 'watchlist') {
    $stmt = $pdo->query("SELECT * FROM watchlist ORDER BY id DESC");
    $watchlistItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pageTitle = "⭐ Watchlist Pribadi Anda";
} elseif ($action === 'history') {
    $stmt = $pdo->query("SELECT * FROM search_history ORDER BY id DESC LIMIT 50");
    $historyItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pageTitle = "🕒 Riwayat Pencarian Pengguna";
} else {
    $data = callTMDB("/trending/{$type}/day", ['page' => $page]);
    $items = $data['results'] ?? [];
    $totalPages = $data['total_pages'] ?? 1;
}

$detailId = isset($_GET['detail_id']) ? intval($_GET['detail_id']) : 0;
$detailData = null;
$detailCredits = null;
$detailVideos = null;
$detailSimilar = null;
$localReviews = [];

if ($detailId > 0) {
    if ($type === 'movie' || $type === 'tv') {
        $detailData = callTMDB("/{$type}/{$detailId}");
        $detailCredits = callTMDB("/{$type}/{$detailId}/credits");
        $detailVideos = callTMDB("/{$type}/{$detailId}/videos");
        $detailSimilar = callTMDB("/{$type}/{$detailId}/recommendations");
        if (empty($detailSimilar['results'])) {
            $detailSimilar = callTMDB("/{$type}/{$detailId}/similar");
        }
        
        $revStmt = $pdo->prepare("SELECT * FROM reviews WHERE movie_id = ? ORDER BY id DESC");
        $revStmt->execute([$detailId]);
        $localReviews = $revStmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($type === 'person') {
        $detailData = callTMDB("/person/{$detailId}");
        $detailCredits = callTMDB("/person/{$detailId}/movie_credits");
    }
}

// Parameter Komparasi Objektif
$comp1 = isset($_GET['comp_1']) ? intval($_GET['comp_1']) : 0;
$comp2 = isset($_GET['comp_2']) ? intval($_GET['comp_2']) : 0;
$movieComp1 = $comp1 > 0 ? callTMDB("/movie/{$comp1}") : null;
$movieComp2 = $comp2 > 0 ? callTMDB("/movie/{$comp2}") : null;

$popularForCompare = callTMDB("/movie/popular", ['page' => 1])['results'] ?? [];
$compareSearchQ = isset($_GET['comp_q']) ? trim($_GET['comp_q']) : '';
$compareSearchResults = [];
if (!empty($compareSearchQ)) {
    $compareSearchResults = callTMDB("/search/movie", ['query' => $compareSearchQ])['results'] ?? [];
}
?>
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emovietion - Entertainment Center</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: { darker: '#05070b', card: '#0f172a' }
                }
            }
        }
    </script>
    <style>
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="bg-darker text-slate-100 min-h-screen flex flex-col justify-between font-sans selection:bg-rose-500 selection:text-white">

    <!-- Header Navigasi Utama[cite: 1] -->
    <header class="bg-slate-900/95 border-b border-slate-800 sticky top-0 z-40 backdrop-blur-md">
        <div class="max-w-7xl mx-auto px-4 py-3 flex flex-col lg:flex-row justify-between items-center gap-4">
            <div class="flex items-center justify-between w-full lg:w-auto gap-4">
                <a href="?" class="text-xl sm:text-2xl font-black tracking-wider text-rose-500 flex items-center gap-2">
                    Emovietion<span class="text-white">Sites</span>
                    <span class="text-[8px] sm:text-[9px] bg-rose-600/20 text-rose-400 border border-rose-500/30 px-2 py-0.5 rounded-full uppercase tracking-widest hidden sm:inline-block">NETFLIX STYLE</span>
                </a>
                
                <!-- Jam Real-time di Header (Responsive untuk Mobile & Desktop) -->
                <div id="realtime-clock" class="bg-slate-950 border border-slate-800 text-amber-400 text-xs font-bold px-3 py-1.5 rounded-xl flex items-center gap-1.5 shadow-inner">
                    <span>⏰</span> <span id="clock-display">00:00:00</span>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-center gap-2 w-full lg:w-auto">
                <div class="flex bg-slate-950 p-1 rounded-xl border border-slate-800 text-xs font-bold">
                    <a href="?type=movie" class="px-3 py-1.5 rounded-lg transition <?= $type === 'movie' ? 'bg-rose-600 text-white' : 'text-slate-400 hover:text-white' ?>">Film</a>
                    <a href="?type=tv" class="px-3 py-1.5 rounded-lg transition <?= $type === 'tv' ? 'bg-rose-600 text-white' : 'text-slate-400 hover:text-white' ?>">Serial TV</a>
                    <a href="?type=person" class="px-3 py-1.5 rounded-lg transition <?= $type === 'person' ? 'bg-rose-600 text-white' : 'text-slate-400 hover:text-white' ?>">Artis</a>
                </div>

                <nav class="flex items-center gap-2 text-xs font-semibold">
                    <a href="?action=compare" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-amber-400 transition">⚔️ Komparasi</a>
                    <a href="?action=watchlist" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-rose-400 transition">⭐ Watchlist</a>
                    <a href="?action=history" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 transition hidden sm:inline-block">🕒 Riwayat</a>
                </nav>
            </div>

            <form method="GET" action="" class="w-full lg:w-72 flex gap-2">
                <input type="hidden" name="type" value="<?= $type ?>">
                <input type="hidden" name="action" value="search">
                <input type="text" name="q" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Cari judul atau aktor..." 
                    class="bg-slate-950 border border-slate-700 px-4 py-2 rounded-xl text-xs w-full focus:outline-none focus:border-rose-500">
                <button type="submit" class="bg-rose-600 hover:bg-rose-700 px-4 py-2 rounded-xl text-xs font-bold transition">Cari</button>
            </form>
        </div>
    </header>

    <!-- Main Container[cite: 1] -->
    <main class="max-w-7xl mx-auto px-4 py-6 flex-grow w-full">

        <?php if ($action === 'compare'): ?>
            <!-- Arena Komparasi Film Objektif -->
            <div class="bg-slate-900/60 border border-slate-800 rounded-3xl p-4 sm:p-8 backdrop-blur-md mb-10">
                <h2 class="text-xl sm:text-2xl font-black text-white text-center mb-2">⚔️ Arena Komparasi Objektif Head-to-Head</h2>
                <p class="text-xs text-slate-400 text-center mb-6">Pilih atau cari film melalui card di bawah ini untuk melihat perbandingan performa, rating, anggaran, dan pendapatan secara objektif.</p>
                
                <form method="GET" action="" class="max-w-md mx-auto flex gap-2 mb-8">
                    <input type="hidden" name="action" value="compare">
                    <input type="hidden" name="comp_1" value="<?= $comp1 ?>">
                    <input type="hidden" name="comp_2" value="<?= $comp2 ?>">
                    <input type="text" name="comp_q" value="<?= htmlspecialchars($compareSearchQ) ?>" placeholder="Cari film untuk dibandingkan..." class="bg-slate-950 border border-slate-700 px-4 py-2 rounded-xl text-xs w-full focus:outline-none focus:border-amber-500">
                    <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-slate-950 px-4 py-2 rounded-xl text-xs font-bold transition">Cari</button>
                </form>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 mb-8">
                    <div class="bg-slate-950 p-4 rounded-2xl border border-slate-800 text-center">
                        <h4 class="text-xs font-bold text-slate-400 uppercase mb-2">Slot Film Pertama (A)</h4>
                        <?php if ($movieComp1): ?>
                            <img src="https://image.tmdb.org/t/p/w300<?= $movieComp1['poster_path'] ?>" class="w-28 h-40 object-cover rounded-xl mx-auto mb-2 shadow">
                            <p class="font-bold text-sm text-white"><?= $movieComp1['title'] ?> (<?= substr($movieComp1['release_date'] ?? '', 0, 4) ?>)</p>
                            <a href="?action=compare&comp_1=0&comp_2=<?= $comp2 ?>&comp_q=<?= urlencode($compareSearchQ) ?>" class="text-[10px] text-rose-400 hover:underline mt-1 inline-block">Ganti Film A</a>
                        <?php else: ?>
                            <p class="text-xs text-slate-500 py-12">Belum memilih film. Pilih dari daftar di bawah.</p>
                        <?php endif; ?>
                    </div>

                    <div class="bg-slate-950 p-4 rounded-2xl border border-slate-800 text-center">
                        <h4 class="text-xs font-bold text-slate-400 uppercase mb-2">Slot Film Kedua (B)</h4>
                        <?php if ($movieComp2): ?>
                            <img src="https://image.tmdb.org/t/p/w300<?= $movieComp2['poster_path'] ?>" class="w-28 h-40 object-cover rounded-xl mx-auto mb-2 shadow">
                            <p class="font-bold text-sm text-white"><?= $movieComp2['title'] ?> (<?= substr($movieComp2['release_date'] ?? '', 0, 4) ?>)</p>
                            <a href="?action=compare&comp_1=<?= $comp1 ?>&comp_2=0&comp_q=<?= urlencode($compareSearchQ) ?>" class="text-[10px] text-rose-400 hover:underline mt-1 inline-block">Ganti Film B</a>
                        <?php else: ?>
                            <p class="text-xs text-slate-500 py-12">Belum memilih film. Pilih dari daftar di bawah.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($movieComp1 && $movieComp2): ?>
                    <div class="bg-slate-950 p-4 sm:p-6 rounded-3xl border border-amber-500/30 mb-8">
                        <h3 class="text-base sm:text-lg font-black text-white text-center mb-6">📊 Analisis Perbandingan Objektif</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="bg-slate-900 p-4 rounded-2xl border border-slate-800">
                                <h4 class="font-bold text-sm text-rose-400 mb-3"><?= $movieComp1['title'] ?></h4>
                                <div class="space-y-2 text-xs">
                                    <div class="flex justify-between"><span class="text-slate-400">Rating:</span> <span class="text-amber-400 font-bold">⭐ <?= $movieComp1['vote_average'] ?></span></div>
                                    <div class="flex justify-between"><span class="text-slate-400">Durasi:</span> <span class="text-white font-bold"><?= $movieComp1['runtime'] ?> Menit</span></div>
                                    <div class="flex justify-between"><span class="text-slate-400">Budget:</span> <span class="text-rose-400 font-bold">$<?= number_format($movieComp1['budget']) ?></span></div>
                                    <div class="flex justify-between"><span class="text-slate-400">Revenue:</span> <span class="text-emerald-400 font-bold">$<?= number_format($movieComp1['revenue']) ?></span></div>
                                </div>
                            </div>
                            <div class="bg-slate-900 p-4 rounded-2xl border border-slate-800">
                                <h4 class="font-bold text-sm text-rose-400 mb-3"><?= $movieComp2['title'] ?></h4>
                                <div class="space-y-2 text-xs">
                                    <div class="flex justify-between"><span class="text-slate-400">Rating:</span> <span class="text-amber-400 font-bold">⭐ <?= $movieComp2['vote_average'] ?></span></div>
                                    <div class="flex justify-between"><span class="text-slate-400">Durasi:</span> <span class="text-white font-bold"><?= $movieComp2['runtime'] ?> Menit</span></div>
                                    <div class="flex justify-between"><span class="text-slate-400">Budget:</span> <span class="text-rose-400 font-bold">$<?= number_format($movieComp2['budget']) ?></span></div>
                                    <div class="flex justify-between"><span class="text-slate-400">Revenue:</span> <span class="text-emerald-400 font-bold">$<?= number_format($movieComp2['revenue']) ?></span></div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-6 p-4 bg-slate-900 rounded-2xl border border-slate-800 text-xs text-slate-300">
                            <span class="font-bold text-white block mb-1">💡 Kesimpulan Objektif:</span>
                            <?php
                                $score1 = $movieComp1['vote_average'];
                                $score2 = $movieComp2['vote_average'];
                                if ($score1 > $score2) {
                                    echo "Secara penilaian audiens, <strong>{$movieComp1['title']}</strong> unggul dengan rating ⭐ {$score1} dibandingkan <strong>{$movieComp2['title']}</strong> yang memperoleh ⭐ {$score2}.";
                                } elseif ($score2 > $score1) {
                                    echo "Secara penilaian audiens, <strong>{$movieComp2['title']}</strong> unggul dengan rating ⭐ {$score2} dibandingkan <strong>{$movieComp1['title']}</strong> yang memperoleh ⭐ {$score1}.";
                                } else {
                                    echo "Kedua film memiliki skor rating penonton yang setara yaitu ⭐ {$score1}.";
                                }
                            ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div>
                    <h4 class="text-sm font-bold text-white mb-4">🎬 Pilih Film dari Daftar Card untuk Dibandingkan:</h4>
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
                        <?php 
                        $sourceCards = !empty($compareSearchResults) ? $compareSearchResults : $popularForCompare;
                        foreach ($sourceCards as $cItem): 
                            if (empty($cItem['poster_path'])) continue;
                            $cYear = !empty($cItem['release_date']) ? substr($cItem['release_date'], 0, 4) : '-';
                        ?>
                            <div class="bg-slate-950 p-3 rounded-xl border border-slate-800 flex flex-col justify-between">
                                <div>
                                    <img src="https://image.tmdb.org/t/p/w300<?= $cItem['poster_path'] ?>" class="w-full h-40 sm:h-44 object-cover rounded-lg mb-2">
                                    <h5 class="font-bold text-xs text-white truncate"><?= $cItem['title'] ?></h5>
                                    <p class="text-[10px] text-rose-400 mt-0.5">Tahun: <?= $cYear ?></p>
                                </div>
                                <div class="mt-3 flex gap-1">
                                    <a href="?action=compare&comp_1=<?= $cItem['id'] ?>&comp_2=<?= $comp2 ?>&comp_q=<?= urlencode($compareSearchQ) ?>" class="flex-1 bg-slate-800 hover:bg-amber-500 hover:text-slate-950 text-[10px] font-bold py-1 rounded text-center transition">A</a>
                                    <a href="?action=compare&comp_1=<?= $comp1 ?>&comp_2=<?= $cItem['id'] ?>&comp_q=<?= urlencode($compareSearchQ) ?>" class="flex-1 bg-slate-800 hover:bg-amber-500 hover:text-slate-950 text-[10px] font-bold py-1 rounded text-center transition">B</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        <?php elseif ($action === 'watchlist'): ?>
            <h2 class="text-xl font-bold text-white mb-6">⭐ Daftar Watchlist Pribadi Anda</h2>
            <?php if (empty($watchlistItems)): ?>
                <p class="text-slate-400 text-xs">Belum ada film yang dimasukkan ke watchlist.</p>
            <?php else: ?>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
                    <?php foreach ($watchlistItems as $w): ?>
                        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden p-3 flex flex-col justify-between">
                            <div>
                                <img src="<?= $w['poster'] ?>" class="w-full h-48 sm:h-52 object-cover rounded-lg mb-2">
                                <h4 class="font-bold text-xs truncate text-white"><?= $w['title'] ?></h4>
                                <p class="text-[10px] text-rose-400 mt-0.5">Rilis: <?= substr($w['release_date'], 0, 4) ?></p>
                            </div>
                            <form method="POST" class="mt-3">
                                <input type="hidden" name="watchlist_id" value="<?= $w['id'] ?>">
                                <button type="submit" name="remove_watchlist" class="w-full bg-rose-600/20 text-rose-400 hover:bg-rose-600 hover:text-white text-[10px] font-bold py-1.5 rounded-lg transition">Hapus</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php elseif ($action === 'history'): ?>
            <h2 class="text-xl font-bold text-white mb-6">🕒 Riwayat Pencarian Terakhir</h2>
            <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden">
                <?php foreach ($historyItems as $h): ?>
                    <div class="px-5 py-3 border-b border-slate-800 flex justify-between text-xs">
                        <span class="text-white font-medium"><?= htmlspecialchars($h['keyword']) ?></span>
                        <span class="text-slate-500"><?= $h['searched_at'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <!-- HALAMAN UTAMA ALA NETFLIX -->
            
            <!-- HERO BANNER CAROUSEL -->
            <?php if ($action === 'home' && $type === 'movie' && !empty($heroMovies)): ?>
                <div class="relative rounded-3xl overflow-hidden mb-10 border border-slate-800 shadow-2xl bg-slate-900/90 backdrop-blur-md group/hero">
                    <div class="absolute top-4 left-6 z-30">
                        <span class="bg-rose-600 text-white text-[10px] font-bold px-3 py-1 rounded-full uppercase tracking-wider shadow-lg">🔥 Film Unggulan</span>
                    </div>

                    <div class="relative overflow-hidden w-full">
                        <button onclick="moveHero(-1)" class="absolute left-0 top-1/2 -translate-y-1/2 z-30 bg-slate-950/80 hover:bg-rose-600 text-white w-8 sm:w-10 h-14 sm:h-16 rounded-r-xl border-y border-r border-slate-700 flex items-center justify-center opacity-0 group-hover/hero:opacity-100 transition shadow-2xl backdrop-blur-md">❮</button>
                        <button onclick="moveHero(1)" class="absolute right-0 top-1/2 -translate-y-1/2 z-30 bg-slate-950/80 hover:bg-rose-600 text-white w-8 sm:w-10 h-14 sm:h-16 rounded-l-xl border-y border-l border-slate-700 flex items-center justify-center opacity-0 group-hover/hero:opacity-100 transition shadow-2xl backdrop-blur-md">❯</button>

                        <div id="hero-carousel" class="flex transition-transform duration-700 ease-out no-scrollbar" style="width: <?= count($heroMovies) * 100 ?>%;">
                            <?php foreach ($heroMovies as $index => $hMovie): 
                                $backdropUrl = !empty($hMovie['backdrop_path']) ? "https://image.tmdb.org/t/p/original" . $hMovie['backdrop_path'] : '';
                                $hYear = !empty($hMovie['release_date']) ? substr($hMovie['release_date'], 0, 4) : '';
                            ?>
                                <div class="w-full flex-shrink-0 relative p-6 sm:p-10 flex flex-col md:flex-row items-center gap-6" style="width: calc(100% / <?= count($heroMovies) ?>);">
                                    <?php if ($backdropUrl): ?>
                                        <div class="absolute inset-0 z-0 bg-cover bg-center opacity-20 filter blur-sm" style="background-image: url('<?= $backdropUrl ?>');"></div>
                                    <?php endif; ?>
                                    <div class="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-900/80 to-transparent z-1"></div>

                                    <div class="relative z-10 w-36 sm:w-52 flex-shrink-0">
                                        <img src="https://image.tmdb.org/t/p/w500<?= $hMovie['poster_path'] ?>" class="w-full h-56 sm:h-80 object-cover rounded-2xl shadow-2xl border border-slate-700">
                                    </div>

                                    <div class="relative z-10 flex-grow text-center md:text-left">
                                        <span class="text-xs font-bold text-rose-400 uppercase tracking-widest">Unggulan #<?= $index + 1 ?> <?= $hYear ? "($hYear)" : "" ?></span>
                                        <h1 class="text-xl sm:text-4xl font-black text-white mt-1"><?= $hMovie['title'] ?? $hMovie['name'] ?></h1>
                                        <p class="text-xs sm:text-sm text-slate-300 mt-3 line-clamp-3 sm:line-clamp-4 leading-relaxed max-w-2xl"><?= $hMovie['overview'] ?></p>
                                        <div class="mt-5 flex flex-wrap items-center justify-center md:justify-start gap-3">
                                            <a href="?type=<?= $type ?>&detail_id=<?= $hMovie['id'] ?>" class="bg-rose-600 hover:bg-rose-700 text-white font-bold px-5 py-2.5 rounded-xl text-xs transition shadow-lg flex items-center gap-2">▶ Lihat Detail & Aktor</a>
                                            <span class="text-amber-400 text-xs font-bold bg-slate-950 px-4 py-2.5 rounded-xl border border-slate-800">⭐ Rating: <?= number_format($hMovie['vote_average'], 1) ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="flex justify-center gap-2 pb-6 relative z-20">
                        <?php for ($i = 0; $i < count($heroMovies); $i++): ?>
                            <button onclick="setHeroSlide(<?= $i ?>)" id="hero-dot-<?= $i ?>" class="w-3 h-3 rounded-full transition <?= $i === 0 ? 'bg-rose-600 w-8' : 'bg-slate-600 hover:bg-slate-400' ?>"></button>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Genre Filter[cite: 1] -->
            <div class="flex gap-2 overflow-x-auto pb-4 mb-8 no-scrollbar">
                <a href="?type=<?= $type ?>" class="bg-rose-600 text-white text-xs font-bold px-4 py-2 rounded-full whitespace-nowrap shadow-md">Semua Genre</a>
                <?php foreach ($genresList as $g): ?>
                    <a href="?type=<?= $type ?>&action=genre&genre=<?= $g['id'] ?>&genre_name=<?= urlencode($g['name']) ?>" 
                       class="bg-slate-900 hover:bg-slate-800 text-slate-300 border border-slate-800 text-xs font-semibold px-4 py-2 rounded-full whitespace-nowrap transition">
                        <?= htmlspecialchars($g['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- BARIS SLIDER: TOP 10 & LATEST -->
            <?php if ($action === 'home' && empty($searchQuery) && empty($selectedGenre)): ?>
                
                <!-- TOP 10 -->
                <div class="mb-12 relative group/slider">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-base sm:text-lg font-extrabold text-white flex items-center gap-2">🔥 Top 10 Populer Hari Ini</h2>
                    </div>
                    
                    <button onclick="scrollSlider('slider-top10', -300)" class="absolute left-0 top-1/2 -translate-y-1/2 z-30 bg-slate-950/80 hover:bg-rose-600 text-white w-8 sm:w-10 h-14 sm:h-16 rounded-r-xl border-y border-r border-slate-700 flex items-center justify-center opacity-0 group-hover/slider:opacity-100 transition shadow-2xl backdrop-blur-md">❮</button>
                    <button onclick="scrollSlider('slider-top10', 300)" class="absolute right-0 top-1/2 -translate-y-1/2 z-30 bg-slate-950/80 hover:bg-rose-600 text-white w-8 sm:w-10 h-14 sm:h-16 rounded-l-xl border-y border-l border-slate-700 flex items-center justify-center opacity-0 group-hover/slider:opacity-100 transition shadow-2xl backdrop-blur-md">❯</button>

                    <div id="slider-top10" class="flex gap-4 overflow-x-auto pb-4 pt-2 no-scrollbar scroll-smooth">
                        <?php $rank = 1; foreach ($topTenMovies as $tItem): 
                            $tPoster = !empty($tItem['poster_path']) ? "https://image.tmdb.org/t/p/w500" . $tItem['poster_path'] : "https://via.placeholder.com/500x750?text=No+Image";
                            $tYear = !empty($tItem['release_date']) ? substr($tItem['release_date'], 0, 4) : '-';
                        ?>
                            <div class="w-[160px] sm:w-[210px] min-w-[160px] sm:min-w-[210px] bg-slate-900 border border-slate-800/80 rounded-2xl overflow-hidden flex-shrink-0 relative group shadow-lg hover:border-rose-500/50 transition duration-300 flex flex-col justify-between">
                                <div>
                                    <div class="absolute top-2 left-2 z-10 bg-rose-600 text-white font-black text-xs px-2 py-0.5 rounded-lg shadow-md border border-rose-500">#<?= $rank++ ?></div>
                                    <div class="overflow-hidden h-56 sm:h-64">
                                        <img src="<?= $tPoster ?>" class="w-full h-full object-cover group-hover:scale-110 transition duration-500">
                                    </div>
                                    <div class="p-3 bg-gradient-to-t from-slate-950 to-slate-900">
                                        <h4 class="font-bold text-xs truncate text-white"><?= $tItem['title'] ?? $tItem['name'] ?></h4>
                                        <p class="text-[10px] text-rose-400 mt-0.5 font-medium">Tahun: <?= $tYear ?></p>
                                        <p class="text-[10px] text-slate-400 mt-1 line-clamp-2 leading-relaxed"><?= $tItem['overview'] ?: 'Tidak ada deskripsi singkat.' ?></p>
                                    </div>
                                </div>
                                <div class="p-3 pt-0">
                                    <a href="?type=<?= $type ?>&detail_id=<?= $tItem['id'] ?>" class="block text-center bg-slate-800 hover:bg-rose-600 text-[10px] font-bold py-1.5 rounded-xl transition shadow">Detail & Aktor</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- LATEST MOVIES -->
                <div class="mb-12 relative group/slider2">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-base sm:text-lg font-extrabold text-white flex items-center gap-2">✨ Urutkan dari yang Terbaru (Rilis Baru)</h2>
                    </div>
                    
                    <button onclick="scrollSlider('slider-latest', -300)" class="absolute left-0 top-1/2 -translate-y-1/2 z-30 bg-slate-950/80 hover:bg-rose-600 text-white w-8 sm:w-10 h-14 sm:h-16 rounded-r-xl border-y border-r border-slate-700 flex items-center justify-center opacity-0 group-hover/slider2:opacity-100 transition shadow-2xl backdrop-blur-md">❮</button>
                    <button onclick="scrollSlider('slider-latest', 300)" class="absolute right-0 top-1/2 -translate-y-1/2 z-30 bg-slate-950/80 hover:bg-rose-600 text-white w-8 sm:w-10 h-14 sm:h-16 rounded-l-xl border-y border-l border-slate-700 flex items-center justify-center opacity-0 group-hover/slider2:opacity-100 transition shadow-2xl backdrop-blur-md">❯</button>

                    <div id="slider-latest" class="flex gap-4 overflow-x-auto pb-4 pt-2 no-scrollbar scroll-smooth">
                        <?php foreach ($latestMovies as $lItem): 
                            $lPoster = !empty($lItem['poster_path']) ? "https://image.tmdb.org/t/p/w500" . $lItem['poster_path'] : "https://via.placeholder.com/500x750?text=No+Image";
                            $lYear = !empty($lItem['release_date']) ? substr($lItem['release_date'], 0, 4) : '-';
                        ?>
                            <div class="w-[160px] sm:w-[210px] min-w-[160px] sm:min-w-[210px] bg-slate-900 border border-slate-800/80 rounded-2xl overflow-hidden flex-shrink-0 group shadow-lg hover:border-rose-500/50 transition duration-300 flex flex-col justify-between">
                                <div>
                                    <div class="overflow-hidden h-56 sm:h-64">
                                        <img src="<?= $lPoster ?>" class="w-full h-full object-cover group-hover:scale-110 transition duration-500">
                                    </div>
                                    <div class="p-3 bg-gradient-to-t from-slate-950 to-slate-900">
                                        <h4 class="font-bold text-xs truncate text-white"><?= $lItem['title'] ?? $lItem['name'] ?></h4>
                                        <p class="text-[10px] text-rose-400 mt-0.5 font-medium">Rilis: <?= $lItem['release_date'] ?? $lYear ?></p>
                                        <p class="text-[10px] text-slate-400 mt-1 line-clamp-2 leading-relaxed"><?= $lItem['overview'] ?: 'Tidak ada deskripsi singkat.' ?></p>
                                    </div>
                                </div>
                                <div class="p-3 pt-0">
                                    <a href="?type=<?= $type ?>&detail_id=<?= $lItem['id'] ?>" class="block text-center bg-slate-800 hover:bg-rose-600 text-[10px] font-bold py-1.5 rounded-xl transition shadow">Detail & Aktor</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            <?php endif; ?>

            <!-- EKSPLORASI UTAMA (Menampilkan Keseluruhan Data dari API dengan Pagination) -->
            <div class="flex justify-between items-center mb-6 border-b border-slate-800 pb-4">
                <h2 class="text-base sm:text-lg font-bold text-white"><?= $pageTitle ?></h2>
                <span class="text-xs text-slate-400">Hal <?= $page ?>/<?= min($totalPages, 500) ?></span>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4 sm:gap-5">
                <?php foreach ($items as $item): 
                    $itemId = $item['id'];
                    $itemTitle = $item['title'] ?? $item['name'] ?? 'Tanpa Nama';
                    $itemRating = isset($item['vote_average']) ? number_format($item['vote_average'], 1) : '0';
                    $itemOverview = $item['overview'] ?? 'Tidak ada deskripsi singkat untuk item ini.';
                    $rawDate = $item['release_date'] ?? $item['first_air_date'] ?? '';
                    $itemYear = !empty($rawDate) ? substr($rawDate, 0, 4) : '-';
                    $itemPoster = !empty($item['poster_path']) || !empty($item['profile_path']) 
                        ? "https://image.tmdb.org/t/p/w500" . ($item['poster_path'] ?? $item['profile_path']) 
                        : "https://via.placeholder.com/500x750?text=No+Image";
                ?>
                    <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl flex flex-col justify-between group hover:border-rose-500/50 transition duration-300">
                        <div>
                            <div class="relative overflow-hidden h-52 sm:h-64">
                                <img src="<?= $itemPoster ?>" class="w-full h-full object-cover group-hover:scale-110 transition duration-500">
                                <?php if ($type !== 'person'): ?>
                                    <span class="absolute top-2 right-2 bg-slate-950/80 text-amber-400 text-xs font-bold px-2 py-0.5 rounded-lg border border-slate-700 backdrop-blur-md">⭐ <?= $itemRating ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="p-3">
                                <h3 class="font-bold text-sm truncate text-white"><?= htmlspecialchars($itemTitle) ?></h3>
                                <p class="text-[10px] text-rose-400 font-medium mt-0.5">Tahun Rilis: <?= $itemYear ?></p>
                                <p class="text-[10px] text-slate-400 mt-1 line-clamp-3 leading-relaxed"><?= htmlspecialchars($itemOverview) ?></p>
                            </div>
                        </div>
                        <div class="p-3 pt-0">
                            <a href="?type=<?= $type ?>&detail_id=<?= $itemId ?>&page=<?= $page ?>&action=<?= $action ?>&genre=<?= $selectedGenre ?>" 
                               class="block text-center bg-slate-800 hover:bg-rose-600 text-xs font-bold py-2 rounded-xl transition">Detail & Aktor</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination Navigasi -->
            <div class="flex justify-center items-center gap-4 mt-10">
                <?php if ($page > 1): ?>
                    <a href="?type=<?= $type ?>&action=<?= $action ?>&genre=<?= $selectedGenre ?>&genre_name=<?= urlencode($genreName) ?>&page=<?= $page - 1 ?>" 
                       class="bg-slate-800 hover:bg-slate-700 px-5 py-2 rounded-xl text-xs font-bold">← Sebelumnya</a>
                <?php endif; ?>
                <span class="text-xs text-slate-400">Page <?= $page ?></span>
                <?php if ($page < $totalPages && $page < 500): ?>
                    <a href="?type=<?= $type ?>&action=<?= $action ?>&genre=<?= $selectedGenre ?>&genre_name=<?= urlencode($genreName) ?>&page=<?= $page + 1 ?>" 
                       class="bg-rose-600 hover:bg-rose-700 px-5 py-2 rounded-xl text-xs font-bold">Selanjutnya →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </main>

    <!-- MODAL DETAIL & PEMERAN UTAMA (TOP 5 MC) + POPUP SHOW MORE AKTOR -->
    <?php if ($detailData): ?>
        <div class="fixed inset-0 z-50 bg-slate-950/85 backdrop-blur-md flex items-center justify-center p-4">
            <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-4xl w-full max-h-[90vh] overflow-y-auto p-4 sm:p-6 relative">
                <a href="?type=<?= $type ?>&page=<?= $page ?>&action=<?= $action ?>&genre=<?= $selectedGenre ?>" class="absolute top-4 right-4 bg-slate-800 text-white w-8 h-8 rounded-full flex items-center justify-center font-bold">✕</a>
                
                <?php if ($type === 'person'): ?>
                    <div class="flex flex-col sm:flex-row gap-6">
                        <img src="https://image.tmdb.org/t/p/w500<?= $detailData['profile_path'] ?>" class="w-40 sm:w-48 h-56 sm:h-64 object-cover rounded-xl shadow-lg mx-auto">
                        <div>
                            <h2 class="text-xl sm:text-2xl font-black text-white"><?= $detailData['name'] ?></h2>
                            <p class="text-xs text-slate-400 mt-1">Lahir: <?= $detailData['birthday'] ?? '-' ?> di <?= $detailData['place_of_birth'] ?? '-' ?></p>
                            <p class="text-xs text-slate-300 mt-4 leading-relaxed"><?= $detailData['biography'] ?: 'Tidak ada biografi.' ?></p>
                        </div>
                    </div>
                <?php else: 
                    $detailYear = !empty($detailData['release_date']) ? substr($detailData['release_date'], 0, 4) : (!empty($detailData['first_air_date']) ? substr($detailData['first_air_date'], 0, 4) : '-');
                ?>
                    <div class="flex flex-col sm:flex-row gap-6">
                        <img src="https://image.tmdb.org/t/p/w500<?= $detailData['poster_path'] ?>" class="w-44 sm:w-48 h-64 sm:h-72 object-cover rounded-xl shadow-lg mx-auto">
                        <div class="flex-grow">
                            <h2 class="text-xl sm:text-2xl font-black text-white"><?= $detailData['title'] ?? $detailData['name'] ?> <span class="text-rose-400 font-normal text-lg">(<?= $detailYear ?>)</span></h2>
                            <p class="text-xs text-rose-400 italic mt-1">"<?= $detailData['tagline'] ?? '' ?>"</p>
                            <p class="text-xs text-slate-300 mt-3 leading-relaxed"><?= $detailData['overview'] ?></p>

                            <form method="POST" class="mt-4">
                                <input type="hidden" name="movie_id" value="<?= $detailData['id'] ?>">
                                <input type="hidden" name="title" value="<?= htmlspecialchars($detailData['title'] ?? $detailData['name']) ?>">
                                <input type="hidden" name="poster" value="https://image.tmdb.org/t/p/w500<?= $detailData['poster_path'] ?>">
                                <input type="hidden" name="vote_average" value="<?= $detailData['vote_average'] ?>">
                                <input type="hidden" name="release_date" value="<?= $detailData['release_date'] ?? $detailData['first_air_date'] ?? '' ?>">
                                <button type="submit" name="add_watchlist" class="bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold px-4 py-2 rounded-xl text-xs transition">⭐ Simpan ke Watchlist</button>
                            </form>
                        </div>
                    </div>

                    <!-- BAGIAN PEMERAN / AKTOR (Top 5 Main Cast + Tombol Show More) -->
                    <?php if (!empty($detailCredits['cast'])): ?>
                        <div class="mt-8 border-t border-slate-800 pt-6">
                            <div class="flex justify-between items-center mb-4">
                                <h4 class="text-xs font-bold text-white uppercase tracking-wider">🎭 Pemeran Utama (Top 5 MC)</h4>
                                <?php if (count($detailCredits['cast']) > 5): ?>
                                    <a href="?type=<?= $type ?>&detail_id=<?= $detailId ?>&show_all_cast=1" class="text-xs text-rose-400 hover:underline font-bold">Show More (Seluruh Aktor) →</a>
                                <?php endif; ?>
                            </div>

                            <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
                                <?php 
                                $topCast = array_slice($detailCredits['cast'], 0, 5);
                                foreach ($topCast as $actor): 
                                    $actorPhoto = !empty($actor['profile_path']) ? "https://image.tmdb.org/t/p/w200" . $actor['profile_path'] : "https://via.placeholder.com/200x300?text=No+Photo";
                                ?>
                                    <div class="bg-slate-950 p-2.5 rounded-xl border border-slate-800 text-center">
                                        <img src="<?= $actorPhoto ?>" class="w-full h-32 sm:h-36 object-cover rounded-lg mb-2">
                                        <h5 class="font-bold text-xs text-white truncate"><?= htmlspecialchars($actor['name']) ?></h5>
                                        <p class="text-[10px] text-slate-400 truncate"><?= htmlspecialchars($actor['character'] ?? '') ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- TRAILER YOUTUBE -->
                    <?php 
                    $youtubeKey = '';
                    if (!empty($detailVideos['results'])) {
                        foreach ($detailVideos['results'] as $vid) {
                            if ($vid['site'] === 'YouTube' && $vid['type'] === 'Trailer') {
                                $youtubeKey = $vid['key'];
                                break;
                            }
                        }
                    }
                    if ($youtubeKey): 
                    ?>
                        <div class="mt-8 border-t border-slate-800 pt-6">
                            <h4 class="text-xs font-bold text-white mb-2 uppercase">🎬 Trailer Resmi</h4>
                            <div class="aspect-video w-full rounded-xl overflow-hidden border border-slate-800">
                                <iframe src="https://www.youtube.com/embed/<?= $youtubeKey ?>" class="w-full h-full" allowfullscreen></iframe>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- REKOMENDASI FILM / TV -->
                    <?php if (!empty($detailSimilar['results'])): ?>
                        <div class="mt-8 border-t border-slate-800 pt-6 relative group/modal-slider">
                            <h4 class="text-xs font-bold text-white mb-4 uppercase">🎯 Rekomendasi Untuk Anda</h4>
                            
                            <button onclick="scrollSlider('slider-modal-sim', -250)" class="absolute left-0 top-1/2 z-30 bg-slate-950/80 hover:bg-rose-600 text-white w-8 h-14 rounded-r-xl border-y border-r border-slate-700 flex items-center justify-center opacity-0 group-hover/modal-slider:opacity-100 transition shadow-2xl backdrop-blur-md">❮</button>
                            <button onclick="scrollSlider('slider-modal-sim', 250)" class="absolute right-0 top-1/2 z-30 bg-slate-950/80 hover:bg-rose-600 text-white w-8 h-14 rounded-l-xl border-y border-l border-slate-700 flex items-center justify-center opacity-0 group-hover/modal-slider:opacity-100 transition shadow-2xl backdrop-blur-md">❯</button>

                            <div id="slider-modal-sim" class="flex gap-3 overflow-x-auto pb-3 pt-1 no-scrollbar scroll-smooth">
                                <?php foreach (array_slice($detailSimilar['results'], 0, 10) as $sim): 
                                    if (empty($sim['poster_path'])) continue;
                                    $simYear = !empty($sim['release_date']) ? substr($sim['release_date'], 0, 4) : '';
                                ?>
                                    <div class="min-w-[120px] max-w-[120px] sm:min-w-[130px] sm:max-w-[130px] bg-slate-950 border border-slate-800 rounded-xl overflow-hidden flex-shrink-0 group hover:border-rose-500/50 transition">
                                        <div class="h-36 sm:h-44 overflow-hidden">
                                            <img src="https://image.tmdb.org/t/p/w300<?= $sim['poster_path'] ?>" class="w-full h-full object-cover group-hover:scale-110 transition duration-500">
                                        </div>
                                        <div class="p-2">
                                            <p class="font-bold text-[10px] truncate text-white"><?= $sim['title'] ?? $sim['name'] ?></p>
                                            <p class="text-[9px] text-rose-400"><?= $simYear ?></p>
                                            <a href="?type=<?= $type ?>&detail_id=<?= $sim['id'] ?>&page=<?= $page ?>&action=<?= $action ?>" class="mt-1.5 block text-center bg-slate-800 hover:bg-rose-600 text-[9px] font-bold py-1 rounded-lg transition">Lihat</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- ULASAN & RATING LOKAL -->
                    <div class="mt-8 border-t border-slate-800 pt-6">
                        <h4 class="text-xs font-bold text-white mb-4 uppercase">💬 Ulasan & Rating Mandiri Pengguna</h4>
                        <form method="POST" class="space-y-3 mb-6 bg-slate-950 p-4 rounded-xl border border-slate-800">
                            <input type="hidden" name="movie_id" value="<?= $detailData['id'] ?>">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <input type="text" name="username" placeholder="Nama Anda" class="bg-slate-900 border border-slate-700 px-3 py-1.5 rounded-lg text-xs focus:outline-none">
                                <select name="rating" class="bg-slate-900 border border-slate-700 px-3 py-1.5 rounded-lg text-xs focus:outline-none">
                                    <option value="5">⭐⭐⭐⭐⭐ (5/5)</option>
                                    <option value="4">⭐⭐⭐⭐ (4/5)</option>
                                    <option value="3">⭐⭐⭐ (3/5)</option>
                                    <option value="2">⭐⭐ (2/5)</option>
                                    <option value="1">⭐ (1/5)</option>
                                </select>
                            </div>
                            <textarea name="comment" placeholder="Tulis ulasan Anda..." class="w-full bg-slate-900 border border-slate-700 px-3 py-2 rounded-lg text-xs focus:outline-none"></textarea>
                            <button type="submit" name="submit_review" class="bg-rose-600 hover:bg-rose-700 px-4 py-1.5 rounded-lg text-xs font-bold text-white transition">Kirim Ulasan</button>
                        </form>

                        <div class="space-y-3">
                            <?php foreach ($localReviews as $rev): ?>
                                <div class="bg-slate-950 p-3 rounded-xl border border-slate-800 text-xs">
                                    <div class="flex justify-between font-bold text-white">
                                        <span><?= htmlspecialchars($rev['username']) ?></span>
                                        <span class="text-amber-400">⭐ <?= $rev['rating'] ?>/5</span>
                                    </div>
                                    <p class="text-slate-300 mt-1"><?= htmlspecialchars($rev['comment']) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- POPUP KEDUA: SHOW ALL CAST -->
    <?php if ($showAllCast && $detailId > 0 && !empty($detailCredits['cast'])): ?>
        <div class="fixed inset-0 z-60 bg-slate-950/90 backdrop-blur-md flex items-center justify-center p-4">
            <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-3xl w-full max-h-[85vh] overflow-y-auto p-4 sm:p-6 relative">
                <a href="?type=<?= $type ?>&detail_id=<?= $detailId ?>&page=<?= $page ?>&action=<?= $action ?>" class="absolute top-4 right-4 bg-slate-800 text-white w-8 h-8 rounded-full flex items-center justify-center font-bold">✕</a>
                
                <h3 class="text-lg font-black text-white mb-4">🎭 Seluruh Daftar Pemeran (Cast)</h3>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <?php foreach ($detailCredits['cast'] as $actor): 
                        $actorPhoto = !empty($actor['profile_path']) ? "https://image.tmdb.org/t/p/w200" . $actor['profile_path'] : "https://via.placeholder.com/200x300?text=No+Photo";
                    ?>
                        <div class="bg-slate-950 p-3 rounded-xl border border-slate-800 text-center flex flex-col justify-between">
                            <div>
                                <img src="<?= $actorPhoto ?>" class="w-full h-36 sm:h-40 object-cover rounded-lg mb-2">
                                <h5 class="font-bold text-xs text-white"><?= htmlspecialchars($actor['name']) ?></h5>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-1 italic"><?= htmlspecialchars($actor['character'] ?? '') ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- SCRIPT REAL-TIME CLOCK & CAROUSEL -->
    <script>
        // Fungsi Jam Real-time
        function updateRealtimeClock() {
            const now = new Date();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            const clockEl = document.getElementById('clock-display');
            if (clockEl) {
                clockEl.textContent = `${hours}:${minutes}:${seconds}`;
            }
        }
        setInterval(updateRealtimeClock, 1000);
        updateRealtimeClock();

        function scrollSlider(sliderId, distance) {
            const slider = document.getElementById(sliderId);
            if (slider) { slider.scrollBy({ left: distance, behavior: 'smooth' }); }
        }

        let currentHeroIndex = 0;
        const totalHeroSlides = <?= count($heroMovies) ?>;

        function updateHeroCarousel() {
            const carousel = document.getElementById('hero-carousel');
            if (carousel) {
                const percentage = currentHeroIndex * (100 / totalHeroSlides);
                carousel.style.transform = `translateX(-${percentage}%)`;
            }
            for (let i = 0; i < totalHeroSlides; i++) {
                const dot = document.getElementById(`hero-dot-${i}`);
                if (dot) {
                    dot.className = (i === currentHeroIndex) ? "w-8 h-3 rounded-full transition bg-rose-600" : "w-3 h-3 rounded-full transition bg-slate-600 hover:bg-slate-400";
                }
            }
        }

        function moveHero(direction) {
            currentHeroIndex = (currentHeroIndex + direction + totalHeroSlides) % totalHeroSlides;
            updateHeroCarousel();
        }

        function setHeroSlide(index) {
            currentHeroIndex = index;
            updateHeroCarousel();
        }

        if (totalHeroSlides > 0) {
            setInterval(() => { moveHero(1); }, 6000);
        }
    </script>

    <!-- Footer[cite: 1] -->
    <footer class="bg-slate-950 border-t border-slate-900 py-8 text-center text-xs text-slate-500 mt-16 space-y-2">
        <p>This product uses the TMDB API but is not endorsed or certified by TMDB.</p>
        <p>&copy; 2026 Emovietion Sites. Kleon Alfeus Effendi 12 Informatika 2</p>
    </footer>

</body>
</html>