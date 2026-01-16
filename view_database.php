<?php
/**
 * SQLite Database Viewer
 * View contents of crawler.db in a friendly web interface
 */

$dbPath = __DIR__ . '/data/crawler.db';

if (!file_exists($dbPath)) {
    die('<h1>Error</h1><p>Database file not found: ' . $dbPath . '</p>');
}

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('<h1>Error</h1><p>Cannot open database: ' . $e->getMessage() . '</p>');
}

// Get table selection
$table = $_GET['table'] ?? 'urls';
$limit = (int)($_GET['limit'] ?? 50);
$offset = (int)($_GET['offset'] ?? 0);

// Get available tables
$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

// Get stats
$stats = [];
foreach ($tables as $tbl) {
    $count = $db->query("SELECT COUNT(*) FROM " . $tbl)->fetchColumn();
    $stats[$tbl] = $count;
}

// Get table data
$data = [];
$columns = [];
if (in_array($table, $tables)) {
    $stmt = $db->query("SELECT * FROM $table LIMIT $limit OFFSET $offset");
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($data)) {
        $columns = array_keys($data[0]);
    }
}

$dbSize = filesize($dbPath);
$dbSizeFormatted = $dbSize >= 1048576 
    ? number_format($dbSize / 1048576, 2) . ' MB'
    : number_format($dbSize / 1024, 2) . ' KB';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Viewer - Web Crawler</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
        }
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            padding: 30px;
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-card h3 {
            font-size: 14px;
            color: #6c757d;
            text-transform: uppercase;
            margin-bottom: 10px;
        }
        .stat-card .value {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
        }
        .tabs {
            display: flex;
            padding: 0 30px;
            background: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            overflow-x: auto;
        }
        .tab {
            padding: 15px 25px;
            cursor: pointer;
            border: none;
            background: none;
            font-size: 14px;
            font-weight: 500;
            color: #6c757d;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
            white-space: nowrap;
        }
        .tab:hover {
            color: #667eea;
            background: rgba(102, 126, 234, 0.1);
        }
        .tab.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
        .content {
            padding: 30px;
        }
        .table-container {
            overflow-x: auto;
            border: 1px solid #dee2e6;
            border-radius: 8px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
            position: sticky;
            top: 0;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #f1f3f5;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .url-cell {
            max-width: 400px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-error { background: #f8d7da; color: #721c24; }
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
            padding: 20px;
        }
        .btn {
            padding: 8px 16px;
            border: 1px solid #dee2e6;
            background: white;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            color: #495057;
            font-size: 14px;
        }
        .btn:hover {
            background: #f8f9fa;
            border-color: #667eea;
            color: #667eea;
        }
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        .no-data svg {
            width: 80px;
            height: 80px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🗄️ Database Viewer</h1>
            <p>Database: <?= htmlspecialchars(basename($dbPath)) ?> (<?= $dbSizeFormatted ?>)</p>
        </div>

        <div class="stats">
            <?php foreach ($stats as $tbl => $count): ?>
            <div class="stat-card">
                <h3><?= htmlspecialchars($tbl) ?></h3>
                <div class="value"><?= number_format($count) ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="tabs">
            <?php foreach ($tables as $tbl): ?>
            <a href="?table=<?= urlencode($tbl) ?>&limit=<?= $limit ?>" 
               class="tab <?= $table === $tbl ? 'active' : '' ?>">
                <?= htmlspecialchars(ucfirst($tbl)) ?> (<?= $stats[$tbl] ?>)
            </a>
            <?php endforeach; ?>
        </div>

        <div class="content">
            <?php if (empty($data)): ?>
            <div class="no-data">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                          d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                </svg>
                <h3>No data found</h3>
                <p>The table "<?= htmlspecialchars($table) ?>" is empty.</p>
            </div>
            <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <?php foreach ($columns as $col): ?>
                            <th><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $col))) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data as $row): ?>
                        <tr>
                            <?php foreach ($columns as $col): ?>
                            <td>
                                <?php
                                $value = $row[$col];
                                if ($col === 'status') {
                                    $badgeClass = $value === 'crawled' ? 'badge-success' : 
                                                 ($value === 'pending' ? 'badge-pending' : 'badge-error');
                                    echo '<span class="badge ' . $badgeClass . '">' . htmlspecialchars($value) . '</span>';
                                } elseif ($col === 'url' && filter_var($value, FILTER_VALIDATE_URL)) {
                                    // Make URL clickable to open in new tab
                                    echo '<a href="' . htmlspecialchars($value) . '" target="_blank" style="color: #667eea; text-decoration: none;" title="' . htmlspecialchars($value) . '">' . 
                                         htmlspecialchars(strlen($value) > 60 ? substr($value, 0, 60) . '...' : $value) . '</a>';
                                } elseif (in_array($col, ['source_url', 'base_url']) && strlen($value) > 60) {
                                    echo '<span class="url-cell" title="' . htmlspecialchars($value) . '">' . 
                                         htmlspecialchars(substr($value, 0, 60)) . '...</span>';
                                } elseif ($col === 'size' && is_numeric($value) && $value > 0) {
                                    // Format file size
                                    if ($value >= 1073741824) {
                                        echo number_format($value / 1073741824, 2) . ' GB';
                                    } elseif ($value >= 1048576) {
                                        echo number_format($value / 1048576, 2) . ' MB';
                                    } elseif ($value >= 1024) {
                                        echo number_format($value / 1024, 2) . ' KB';
                                    } else {
                                        echo $value . ' B';
                                    }
                                } elseif (in_array($col, ['created_at', 'crawled_at', 'found_at', 'started_at', 'completed_at'])) {
                                    echo $value ? date('Y-m-d H:i:s', strtotime($value)) : '-';
                                } else {
                                    echo htmlspecialchars($value ?? '-');
                                }
                                ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($stats[$table] > $limit): ?>
            <div class="pagination">
                <?php if ($offset > 0): ?>
                <a href="?table=<?= urlencode($table) ?>&limit=<?= $limit ?>&offset=<?= max(0, $offset - $limit) ?>" 
                   class="btn">← Previous</a>
                <?php endif; ?>
                
                <span>Showing <?= $offset + 1 ?>-<?= min($offset + $limit, $stats[$table]) ?> of <?= $stats[$table] ?></span>
                
                <?php if ($offset + $limit < $stats[$table]): ?>
                <a href="?table=<?= urlencode($table) ?>&limit=<?= $limit ?>&offset=<?= $offset + $limit ?>" 
                   class="btn">Next →</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
