# Data Directory

This directory stores the SQLite database and related data files for the Web Crawler.

## Files Created Automatically

When you run the crawler with database mode enabled, the following files will be created:

- **crawler.db** - Main SQLite database containing:
  - `urls` table - Tracks visited URLs
  - `files` table - Stores discovered files
  - `sessions` table - Manages crawl sessions

## Database Schema

### URLs Table
```sql
CREATE TABLE urls (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    url TEXT UNIQUE NOT NULL,
    base_url TEXT NOT NULL,
    status TEXT DEFAULT 'pending',
    depth INTEGER DEFAULT 0,
    http_code INTEGER,
    crawled_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Files Table
```sql
CREATE TABLE files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    url TEXT UNIQUE NOT NULL,
    filename TEXT,
    type TEXT,
    size INTEGER,
    source_url TEXT,
    found_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Sessions Table
```sql
CREATE TABLE sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    base_url TEXT NOT NULL,
    max_depth INTEGER,
    max_pages INTEGER,
    pages_crawled INTEGER DEFAULT 0,
    files_found INTEGER DEFAULT 0,
    status TEXT DEFAULT 'running',
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP
);
```

## Notes

- Database files are automatically ignored by git (see `.gitignore`)
- The directory and database will be created automatically on first crawl
- You can safely delete `crawler.db` to start fresh
- Database path can be customized in crawler configuration
