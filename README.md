# 🕷️ Web Crawler

> A powerful, modern PHP-based web crawler with an intuitive UI for discovering, previewing, and downloading files from any website.

![PHP Version](https://img.shields.io/badge/PHP-8.0+-777BB4?style=flat&logo=php)
![License](https://img.shields.io/badge/License-MIT-green?style=flat)
![Status](https://img.shields.io/badge/Status-Active-success?style=flat)

---

## ✨ Key Features

### 🎯 Smart Crawling Engine
- **Multi-format Detection**: Automatically finds 6 categories of files
  - 📄 **Documents**: PDF, DOCX, XLSX, PPTX, TXT, CSV, RTF, ODT
  - 🖼️ **Images**: JPG, PNG, GIF, SVG, WEBP, ICO, BMP, TIFF
  - 🎬 **Videos**: MP4, AVI, MKV, MOV, WEBM, WMV, FLV
  - 🎵 **Audio**: MP3, WAV, FLAC, M4A, OGG, AAC
  - 📦 **Archives**: ZIP, RAR, 7Z, TAR, GZ, ISO
  - 💻 **Code**: HTML, CSS, JS, PHP, JSON, Python, SQL, XML

- **Intelligent Crawling**
  - ✅ Respects robots.txt rules
  - ⚡ Configurable depth and page limits
  - 🛡️ Same-domain restriction for safety
  - ⏱️ Automatic timeout protection
  - 📊 Real-time progress tracking

### 🎨 Modern Interface
- **Minimal Design**: Clean, theater-mode file preview with 85% opacity backdrop
- **Dynamic Search**: Instantly filter through all discovered files
- **Smart Filters**: Dropdown menu with category-based filtering
- **Bulk Operations**: Select all pages, download multiple files as ZIP
- **File Preview**: Built-in viewer for images, videos, audio, PDFs, and text files
- **Responsive Design**: Works seamlessly on desktop and mobile devices

### 🚀 Advanced Features
- **Session-based Progress**: Track crawling progress in real-time
- **Pagination**: Clean display with 10 files per page
- **Download Options**: Individual file download or bulk ZIP export
- **Circular Action Buttons**: Beautiful floating icons for open/download actions
- **Error Handling**: Graceful fallback for CORS-blocked files
- **Memory Efficient**: Optimized for large-scale crawling

---

## 📋 Requirements

| Component | Version | Purpose |
|-----------|---------|---------|
| **PHP** | 8.0+ | Core runtime |
| **Web Server** | Apache/Nginx | HTTP serving |
| **php-curl** | - | HTTP requests |
| **php-dom** | - | HTML parsing |
| **php-zip** | - | Archive creation |
| **php-libxml** | - | XML processing |

---

## 🛠️ Installation

### Quick Start

```bash
# 1. Clone the repository
git clone https://github.com/yourusername/webcrawler.git
cd webcrawler

# 2. Move to web server directory
mv webcrawler /path/to/htdocs/WebCrawler

# 3. Enable PHP ZIP extension
# Edit php.ini and uncomment:
extension=zip

# 4. Restart Apache
sudo service apache2 restart  # Linux
# or restart XAMPP/WAMP
```

### Manual Setup

1. **Download**: Get the latest release or clone this repository
2. **Deploy**: Place in your web server's root directory (`htdocs`, `www`, or `public_html`)
3. **Configure PHP**: Ensure ZIP extension is enabled in `php.ini`
4. **Permissions**: Set appropriate folder permissions (755 recommended)
5. **Access**: Navigate to `http://localhost/WebCrawler/`

---

## 🎮 Usage Guide

### Basic Workflow

1. **Open Application**
   ```
   http://localhost/WebCrawler/
   ```

2. **Configure Crawl**
   - Enter target website URL
   - Select desired file types from dropdown
   - Set crawl depth (1-5, default: 2)
   - Set max pages to crawl (default: 50)

3. **Start Crawling**
   - Click "Start Crawling Process"
   - Watch real-time progress updates
   - Monitor found files counter

4. **Manage Results**
   - Use search bar to filter files
   - Apply category filters (All, Documents, Images, etc.)
   - Click files to preview in minimal popup
   - Select multiple files and download as ZIP
   - Or download individual files via action buttons

### Advanced Options

#### Category Filters
- **All Files**: Show everything discovered
- **Documents**: PDFs, Office files, text documents
- **Images**: All image formats
- **Videos**: Video files of any type
- **Audio**: Music and audio files
- **Archives**: Compressed files
- **Code**: Source code and scripts

#### Bulk Operations
- **Select All Pages**: Check all files across all pages
- **Download Selected**: Export selected files as ZIP archive
- **Clear Selection**: Deselect all checked items

---

## ⚙️ Configuration

### PHP Settings (`.htaccess`)

```apache
# Execution time (10 minutes)
php_value max_execution_time 600

# Input timeout (5 minutes)
php_value max_input_time 300

# Memory limit (512MB)
php_value memory_limit 512M

# Upload size
php_value upload_max_filesize 100M
php_value post_max_size 100M
```

### Crawler Parameters (`includes/crawler.php`)

```php
$maxExecutionTime = 300;  // 5 minutes max crawl duration
$timeout = 15;            // HTTP request timeout (seconds)
$maxLinksPerPage = 50;    // Links to process per page
$userAgent = "WebCrawler/1.0"; // Custom user agent
```

---

## 📁 Project Structure

```
WebCrawler/
│
├── 📄 index.html                 # Main application UI
├── 📄 README.md                  # Documentation (this file)
├── 📄 .htaccess                 # PHP/Apache configuration
│
├── 📁 api/
│   ├── crawl.php                # Crawl endpoint & progress API
│   └── download_zip.php         # Bulk download handler
│
├── 📁 assets/
│   ├── css/
│   │   └── style.css            # Modern UI styling (685 lines)
│   └── js/
│       └── script.js            # Frontend logic (1067 lines)
│
└── 📁 includes/
    ├── crawler.php              # Core crawling engine
    └── robots_checker.php       # robots.txt compliance
```

---

## 🔌 API Reference

### POST `/api/crawl.php`
Start a new crawl session

**Request:**
```json
{
  "url": "https://example.com",
  "extensions": [".pdf", ".jpg", ".mp4"],
  "maxDepth": 2,
  "maxPages": 50
}
```

**Response:**
```json
{
  "success": true,
  "message": "Crawl completed",
  "files": [
    {
      "url": "https://example.com/file.pdf",
      "filename": "file.pdf",
      "type": "document"
    }
  ],
  "stats": {
    "total_files": 42,
    "pages_crawled": 30,
    "time_elapsed": 45
  }
}
```

### GET `/api/crawl.php?progress=1`
Check crawl progress

**Response:**
```json
{
  "current": 25,
  "total": 50,
  "status": "crawling",
  "time_elapsed": 45,
  "files_found": 12
}
```

### POST `/api/download_zip.php`
Download multiple files as ZIP

**Request:**
```json
{
  "files": [
    "https://example.com/file1.pdf",
    "https://example.com/image.jpg"
  ]
}
```

**Response:** Binary ZIP file download

---

## 🐛 Troubleshooting

### Common Issues

| Problem | Solution |
|---------|----------|
| **ZIP extension not enabled** | Edit `php.ini`: uncomment `extension=zip`, restart Apache |
| **Crawl hangs at "Initializing"** | Check Apache error logs, verify target site is accessible |
| **CORS download errors** | External files may be blocked - use "Open in new tab" |
| **Memory limit errors** | Increase in `.htaccess`: `php_value memory_limit 1024M` |
| **Timeout errors** | Reduce maxPages or increase max_execution_time |
| **No files found** | Check robots.txt, verify file extensions are correct |

### Debug Mode

Enable detailed logging in `includes/crawler.php`:

```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

---

## 🔒 Security & Ethics

### Built-in Safety Features
- ✅ Respects `robots.txt` directives
- ✅ Same-domain crawling only (prevents abuse)
- ✅ No server-side file storage
- ✅ Automatic session cleanup
- ✅ No sensitive data logging
- ✅ CSRF protection on downloads

### Responsible Use Guidelines
1. **Get Permission**: Only crawl websites you own or have explicit permission to crawl
2. **Respect robots.txt**: The tool automatically honors these rules
3. **Be Considerate**: Use reasonable crawl limits to avoid overloading servers
4. **Follow Terms of Service**: Always comply with website TOS
5. **Legal Compliance**: Ensure your use complies with local laws (CFAA, GDPR, etc.)

---

## ⚡ Performance Optimization

### Tips for Faster Crawling

| Strategy | Impact | Recommended Setting |
|----------|--------|---------------------|
| Reduce Max Pages | High | 20-30 for quick scans |
| Limit File Types | Medium | Select only needed extensions |
| Decrease Depth | High | Depth 1-2 for faster results |
| Target Specific URLs | High | Crawl specific sections only |
| Increase Timeout | Low | Only if network is slow |

### Benchmarks
- **Small site** (50 pages, depth 2): ~30-60 seconds
- **Medium site** (200 pages, depth 3): ~2-5 minutes
- **Large site** (500+ pages, depth 4): 5-10 minutes

---

## 🌐 Browser Support

| Browser | Minimum Version | Status |
|---------|----------------|--------|
| Chrome | 90+ | ✅ Fully supported |
| Firefox | 88+ | ✅ Fully supported |
| Safari | 14+ | ✅ Fully supported |
| Edge | 90+ | ✅ Fully supported |
| Opera | 76+ | ✅ Fully supported |

---

## 📝 License

**MIT License** - Copyright (c) 2026

Permission is hereby granted, free of charge, to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of this software.

See [LICENSE](LICENSE) file for full terms.

---

## 🤝 Contributing

Contributions are welcome! Here's how:

1. **Fork** the repository
2. **Create** a feature branch (`git checkout -b feature/amazing-feature`)
3. **Commit** your changes (`git commit -m 'Add amazing feature'`)
4. **Push** to the branch (`git push origin feature/amazing-feature`)
5. **Open** a Pull Request

### Development Guidelines
- Follow existing code style
- Test thoroughly before submitting
- Update documentation for new features
- Keep commits atomic and well-described

---

## 🙏 Acknowledgments

- Built with modern PHP and vanilla JavaScript
- UI inspired by minimal design principles
- Icons: Unicode symbols for lightweight performance
- Testing: XAMPP/Apache environment

---

## 📧 Support

- **Issues**: [GitHub Issues](https://github.com/yourusername/webcrawler/issues)
- **Discussions**: [GitHub Discussions](https://github.com/yourusername/webcrawler/discussions)
- **Email**: your.email@example.com

---

## 📊 Changelog

### v1.0.0 (2026-01-15)
- ✨ Initial release
- 🎨 Modern minimal UI with 85% opacity modal
- 🚀 Smart crawling with robots.txt support
- 📦 Bulk download functionality
- 🔍 Dynamic search and filtering
- 👁️ Built-in file preview for multiple formats

---

## ⚠️ Disclaimer

This tool is for **educational and legitimate purposes only**. Users are responsible for ensuring their use complies with applicable laws, website terms of service, and ethical guidelines. The authors assume no liability for misuse.

**Always obtain permission before crawling websites you don't own.**

---

<div align="center">

Made with ❤️ for web developers and researchers

**[⬆ Back to Top](#-web-crawler)**

</div>

