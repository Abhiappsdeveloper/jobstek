#!/usr/bin/env python3
"""
TekJobs Resume Downloader - Requests-based (No Selenium)
Downloads resumes directly via HTTP without browser automation
Uses the same download API that the web interface uses

Modified to support Laravel storage folder on shared hosting
"""

import sys
import io
import os
import logging
import time
import math
import threading
from datetime import datetime
from urllib.parse import urljoin, urlparse, urlencode
from urllib.request import Request, build_opener, HTTPCookieProcessor
from urllib.error import URLError, HTTPError
import http.cookiejar
import re

# Try to use requests if available, otherwise use urllib
try:
    import requests
    HAS_REQUESTS = True
except ImportError:
    HAS_REQUESTS = False
    requests = None  # Will use urllib wrapper instead

# Fix Unicode/Emoji encoding for Windows
if sys.platform == 'win32':
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8')

# Auto-detect if running on shared hosting (Laravel storage exists)
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
LARAVEL_STORAGE_PATH = os.path.join(SCRIPT_DIR, 'storage', 'resumes')
USE_LARAVEL_STORAGE = os.path.exists(os.path.join(SCRIPT_DIR, 'storage'))

# Setup paths based on environment
if USE_LARAVEL_STORAGE:
    # Running on server with Laravel (Hostinger)
    LOGS_PATH = os.path.join(SCRIPT_DIR, 'storage', 'logs', 'resume_downloader')
    DEFAULT_DOWNLOAD_DIR = LARAVEL_STORAGE_PATH

    # Auto-create all necessary directories with error handling
    try:
        os.makedirs(LOGS_PATH, exist_ok=True)
        os.makedirs(LARAVEL_STORAGE_PATH, exist_ok=True)

        # Try to set permissions (won't fail if permission denied)
        try:
            os.chmod(os.path.join(SCRIPT_DIR, 'storage'), 0o775)
            os.chmod(LARAVEL_STORAGE_PATH, 0o775)
            os.chmod(LOGS_PATH, 0o775)
        except:
            pass  # Permission changes are optional, script will work without them
    except Exception as e:
        print(f"[WARNING] Could not auto-create directories: {e}")
else:
    # Running locally (Windows/Development)
    LOGS_PATH = os.path.dirname(__file__)
    DEFAULT_DOWNLOAD_DIR = os.path.join(SCRIPT_DIR, 'downloads')

    # Auto-create downloads folder locally
    try:
        os.makedirs(DEFAULT_DOWNLOAD_DIR, exist_ok=True)
        # Also ensure progress tracking file directory exists (NEW - doesn't modify existing logic)
        os.makedirs(os.path.dirname(FETCHED_PAGES_TRACKER), exist_ok=True)
    except Exception as e:
        print(f"[WARNING] Could not create downloads directory: {e}")

# Comprehensive directory validation and creation (NEW - doesn't modify previous logic)
def ensure_all_directories():
    """Check if directories exist, create only if not there - WITH LOGGING"""
    required_dirs = [
        LOGS_PATH,
        DEFAULT_DOWNLOAD_DIR,
        os.path.join(SCRIPT_DIR, 'storage'),
        os.path.join(SCRIPT_DIR, 'storage', 'framework'),
        os.path.join(SCRIPT_DIR, 'storage', 'framework', 'cache'),
        os.path.join(SCRIPT_DIR, 'storage', 'framework', 'sessions'),
        os.path.join(SCRIPT_DIR, 'storage', 'framework', 'views'),
        os.path.join(SCRIPT_DIR, 'storage', 'logs'),
        os.path.join(SCRIPT_DIR, 'bootstrap'),
        os.path.join(SCRIPT_DIR, 'bootstrap', 'cache'),
    ]

    print("[DIRS] Checking and creating required directories...")
    for directory in required_dirs:
        try:
            # Check if directory exists first
            if not os.path.exists(directory):
                # Only create if it doesn't exist
                os.makedirs(directory, mode=0o775, exist_ok=True)
                print(f"[DIRS] ✓ Created: {directory}")
            else:
                print(f"[DIRS] ✓ Exists: {directory}")

            # Verify write permission by testing
            test_file = os.path.join(directory, '.write_test')
            with open(test_file, 'w') as f:
                f.write('')
            os.remove(test_file)
        except Exception as e:
            print(f"[DIRS] ✗ Error with {directory}: {e}")

# Logging Setup
LOG_FILE = os.path.join(LOGS_PATH, f"http_downloader_{datetime.now().strftime('%Y%m%d_%H%M%S')}.log")
RESUME_LINKS_FILE = os.path.join(LOGS_PATH, f"resume_links_{datetime.now().strftime('%Y%m%d_%H%M%S')}.txt")
ERROR_TRACKING_FILE = os.path.join(LOGS_PATH, f"error_tracking_{datetime.now().strftime('%Y%m%d_%H%M%S')}.txt")
DOWNLOADED_TRACKER_FILE = os.path.join(DEFAULT_DOWNLOAD_DIR, "downloaded_resumes.txt")  # Persistent tracking file
S3_URLS_TRACKER_FILE = os.path.join(DEFAULT_DOWNLOAD_DIR, "resume_s3_urls.txt")  # Store S3 URLs for quick recovery
FETCHED_PAGES_TRACKER = os.path.join(DEFAULT_DOWNLOAD_DIR, "fetched_pages_progress.txt")  # Track which pages already fetched (NEW)
FETCHED_RESUME_IDS_FILE = os.path.join(DEFAULT_DOWNLOAD_DIR, "fetched_resume_ids.txt")  # Store all extracted resume IDs from pages (NEW)
FETCHED_IDS_INDEX_FILE = os.path.join(DEFAULT_DOWNLOAD_DIR, ".fetched_ids_index")  # Index for fast deduplication (NEW)
PARALLEL_DOWNLOAD_TRACKER = os.path.join(DEFAULT_DOWNLOAD_DIR, ".parallel_download_progress")  # Track which resume IDs processed for parallel download (NEW)
DOWNLOAD_RESUME_TRACKER = os.path.join(DEFAULT_DOWNLOAD_DIR, ".download_resume_progress")  # Track partial downloads for resumability (NEW - BULLETPROOF)
LOGIN_SUCCESS_FLAG = os.path.join(DEFAULT_DOWNLOAD_DIR, ".login_verified")  # Flag to verify login before downloads (NEW - LOGIN CHECK)

# ===== EXTREME BULLETPROOF BAD NETWORK TIMEOUTS (NEW - PHASE 1) =====
BULLETPROOF_DETAIL_TIMEOUT = 600  # 10 minutes for detail page (EXTREME network: 500KB at 5KB/s = 100s + safety)
BULLETPROOF_S3_TIMEOUT = 3600  # 60 minutes for S3 download (EXTREME: 10MB at 5KB/s = 2048s + safety)
BULLETPROOF_CONNECTION_TIMEOUT = 300  # 5 minutes for login (EXTREME: 100KB at 5KB/s = 20s + retry overhead + safety)
BULLETPROOF_MAX_RETRIES = 5  # 5 retry attempts (NEW - PHASE 2)
BULLETPROOF_BASE_DELAY = 10  # 10 seconds base delay (NEW - PHASE 3)

try:
    logging.getLogger().handlers = []
    logger = logging.getLogger(__name__)
    logger.setLevel(logging.INFO)

    file_handler = logging.FileHandler(LOG_FILE, encoding='utf-8')
    file_handler.setFormatter(logging.Formatter('%(asctime)s - %(levelname)s - %(message)s'))
    logger.addHandler(file_handler)

    console_handler = logging.StreamHandler(sys.stdout)
    console_handler.setFormatter(logging.Formatter('%(asctime)s - %(levelname)s - %(message)s'))
    logger.addHandler(console_handler)

except Exception as logging_error:
    print(f"[WARNING] Logging setup issue: {logging_error}")
    logger = logging.getLogger(__name__)
    logger.setLevel(logging.INFO)


# urllib wrapper for compatibility when requests is not available
if not HAS_REQUESTS:
    class _UrllibResponse:
        """Wrapper to make urllib response compatible with requests"""
        def __init__(self, response, status_code):
            self._response = response
            self.status_code = status_code
            self.headers = dict(response.headers) if hasattr(response, 'headers') else {}
            self.text = response.read().decode('utf-8', errors='ignore') if hasattr(response, 'read') else ''
            self._content = None

        def raise_for_status(self):
            if self.status_code >= 400:
                raise HTTPError(None, self.status_code, f"HTTP {self.status_code}", self.headers, None)

        def iter_content(self, chunk_size=8192):
            """Iterate over response content in chunks"""
            if not self._content:
                self._content = self.text.encode('utf-8')
            for i in range(0, len(self._content), chunk_size):
                chunk = self._content[i:i + chunk_size]
                if chunk:
                    yield chunk

    class _UrllibSession:
        """urllib-based session wrapper to be compatible with requests.Session"""
        def __init__(self):
            self.cookie_jar = http.cookiejar.CookieJar()
            self.opener = build_opener(HTTPCookieProcessor(self.cookie_jar))
            self.headers = {}
            # Make cookies accessible like requests.Session
            self.cookies = self.cookie_jar

        def update(self, headers):
            """Update headers"""
            self.headers.update(headers)

        def get(self, url, timeout=15, stream=False, allow_redirects=True):
            """GET request compatible with requests.Session.get()"""
            try:
                req = Request(url, headers=self.headers)
                response = self.opener.open(req, timeout=timeout)
                return _UrllibResponse(response, 200)
            except HTTPError as e:
                return _UrllibResponse(e, e.code)
            except URLError as e:
                raise Exception(f"URLError: {e}")

        def post(self, url, data=None, timeout=15, allow_redirects=True):
            """POST request compatible with requests.Session.post()"""
            try:
                post_data = urlencode(data).encode('utf-8') if data else None
                req = Request(url, data=post_data, headers=self.headers)
                response = self.opener.open(req, timeout=timeout)
                return _UrllibResponse(response, 200)
            except HTTPError as e:
                return _UrllibResponse(e, e.code)
            except URLError as e:
                raise Exception(f"URLError: {e}")


# Initialize tracking files
def initialize_tracking_files():
    """Create tracking files for resume links and errors"""
    try:
        # Ensure logs directory exists (NEW - auto-creates at runtime if missing)
        ensure_file_directory(RESUME_LINKS_FILE)

        # Create resume links file
        with open(RESUME_LINKS_FILE, 'w', encoding='utf-8') as f:
            f.write("Resume Links Log\n")
            f.write(f"Generated: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
            f.write("=" * 80 + "\n\n")

        # Create error tracking file
        with open(ERROR_TRACKING_FILE, 'w', encoding='utf-8') as f:
            f.write("Error Tracking Log\n")
            f.write(f"Generated: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
            f.write("=" * 80 + "\n\n")

        print(f"[INIT] Resume links will be saved to: {RESUME_LINKS_FILE}")
        print(f"[INIT] Errors will be tracked in: {ERROR_TRACKING_FILE}")
        logger.info(f"[INIT] Tracking files initialized")
    except Exception as e:
        print(f"[WARNING] Could not initialize tracking files: {e}")
        logger.warning(f"[INIT] Tracking files issue: {e}")


def log_resume_link(resume_id, detail_url):
    """Log resume link to tracking file"""
    try:
        with open(RESUME_LINKS_FILE, 'a', encoding='utf-8') as f:
            timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            f.write(f"[{timestamp}] Resume ID: {resume_id}\n")
            f.write(f"                Detail URL: {detail_url}\n")
            f.write("\n")
    except Exception as e:
        logger.warning(f"[TRACKING] Could not log resume link: {e}")


def log_error(error_type, resume_id, error_message):
    """Log error to tracking file"""
    try:
        with open(ERROR_TRACKING_FILE, 'a', encoding='utf-8') as f:
            timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            f.write(f"[{timestamp}] {error_type}\n")
            f.write(f"              Resume ID: {resume_id}\n")
            f.write(f"              Error: {error_message}\n")
            f.write("\n")
    except Exception as e:
        logger.warning(f"[TRACKING] Could not log error: {e}")


def load_downloaded_resumes():
    """Load list of already downloaded resume IDs from persistent file"""
    downloaded_set = set()
    try:
        if os.path.exists(DOWNLOADED_TRACKER_FILE):
            with open(DOWNLOADED_TRACKER_FILE, 'r', encoding='utf-8') as f:
                for line in f:
                    line = line.strip()
                    if line and not line.startswith('#'):
                        downloaded_set.add(line)
            print(f"[RESUME-SKIP] Loaded {len(downloaded_set)} previously downloaded resume IDs")
            logger.info(f"[RESUME-SKIP] Loaded {len(downloaded_set)} previously downloaded IDs")
    except Exception as e:
        logger.warning(f"[RESUME-SKIP] Could not load downloaded resumes list: {e}")
    return downloaded_set


def is_resume_downloaded(resume_id, downloaded_set):
    """Check if resume was already downloaded"""
    return resume_id in downloaded_set


def mark_resume_as_downloaded(resume_id):
    """Mark resume as successfully downloaded in persistent file"""
    try:
        # Ensure directory exists before writing (NEW - doesn't modify existing logic)
        ensure_file_directory(DOWNLOADED_TRACKER_FILE)
        with open(DOWNLOADED_TRACKER_FILE, 'a', encoding='utf-8') as f:
            f.write(f"{resume_id}\n")
    except Exception as e:
        logger.warning(f"[RESUME-SKIP] Could not mark resume as downloaded: {e}")


def initialize_downloaded_tracker():
    """Initialize the downloaded resumes tracker file"""
    try:
        # Ensure directory exists
        tracker_dir = os.path.dirname(DOWNLOADED_TRACKER_FILE)
        os.makedirs(tracker_dir, exist_ok=True)

        if not os.path.exists(DOWNLOADED_TRACKER_FILE):
            with open(DOWNLOADED_TRACKER_FILE, 'w', encoding='utf-8') as f:
                f.write("# Downloaded Resumes Tracker\n")
                f.write(f"# Auto-generated: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
                f.write("# This file tracks which resumes have been successfully downloaded\n")
                f.write("# Format: One resume ID per line\n\n")
            print(f"[RESUME-SKIP] Created new tracker file: {DOWNLOADED_TRACKER_FILE}")
            logger.info(f"[RESUME-SKIP] Tracker file created")
    except Exception as e:
        logger.warning(f"[RESUME-SKIP] Could not initialize tracker: {e}")


def initialize_s3_urls_tracker():
    """Initialize the S3 URLs tracker file for instant recovery"""
    try:
        # Ensure directory exists
        tracker_dir = os.path.dirname(S3_URLS_TRACKER_FILE)
        os.makedirs(tracker_dir, exist_ok=True)

        if not os.path.exists(S3_URLS_TRACKER_FILE):
            with open(S3_URLS_TRACKER_FILE, 'w', encoding='utf-8') as f:
                f.write("# Resume S3 URLs Tracker\n")
                f.write(f"# Auto-generated: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
                f.write("# Stores S3 URLs for instant recovery if downloads folder deleted\n")
                f.write("# Format: resume_id|filename|s3_url\n\n")
            print(f"[S3-RECOVERY] Created new S3 URLs tracker: {S3_URLS_TRACKER_FILE}")
            logger.info(f"[S3-RECOVERY] S3 URLs tracker file created")
    except Exception as e:
        logger.warning(f"[S3-RECOVERY] Could not initialize S3 tracker: {e}")


def load_s3_urls():
    """Load S3 URLs from tracker file for instant recovery"""
    s3_urls_dict = {}  # {resume_id: (filename, s3_url)}
    try:
        if os.path.exists(S3_URLS_TRACKER_FILE):
            with open(S3_URLS_TRACKER_FILE, 'r', encoding='utf-8') as f:
                for line in f:
                    line = line.strip()
                    if line and not line.startswith('#'):
                        try:
                            parts = line.split('|')
                            if len(parts) >= 3:
                                resume_id, filename, s3_url = parts[0], parts[1], parts[2]
                                s3_urls_dict[resume_id] = (filename, s3_url)
                        except:
                            pass
        if s3_urls_dict:
            print(f"[S3-RECOVERY] Loaded {len(s3_urls_dict)} S3 URLs for instant recovery")
            logger.info(f"[S3-RECOVERY] Loaded {len(s3_urls_dict)} S3 URLs")
    except Exception as e:
        logger.warning(f"[S3-RECOVERY] Could not load S3 URLs: {e}")
    return s3_urls_dict


def store_s3_url(resume_id, filename, s3_url):
    """Store S3 URL for instant recovery if downloads folder deleted"""
    try:
        # Ensure directory exists before writing (NEW - doesn't modify existing logic)
        ensure_file_directory(S3_URLS_TRACKER_FILE)
        with open(S3_URLS_TRACKER_FILE, 'a', encoding='utf-8') as f:
            f.write(f"{resume_id}|{filename}|{s3_url}\n")
    except Exception as e:
        logger.warning(f"[S3-RECOVERY] Could not store S3 URL: {e}")


def ensure_file_directory(file_path):
    """Ensure directory exists for a file path - create if missing (NEW - doesn't modify existing logic)"""
    try:
        directory = os.path.dirname(file_path)
        if directory and not os.path.exists(directory):
            os.makedirs(directory, mode=0o775, exist_ok=True)
    except:
        pass  # Silent - directory creation is not critical


def get_last_fetched_page():
    """Get the last page number that was already fetched (NEW - doesn't modify existing logic)"""
    try:
        if os.path.exists(FETCHED_PAGES_TRACKER):
            with open(FETCHED_PAGES_TRACKER, 'r', encoding='utf-8') as f:
                content = f.read().strip()
                if content and content.isdigit():
                    return int(content)
    except:
        pass
    return 1  # Start from page 1 if no progress file


def save_fetched_page(page_number):
    """Save the current page number as progress (NEW - doesn't modify existing logic)"""
    try:
        # Ensure directory exists before writing (NEW - doesn't modify existing logic)
        ensure_file_directory(FETCHED_PAGES_TRACKER)
        # Write page number to file
        with open(FETCHED_PAGES_TRACKER, 'w', encoding='utf-8') as f:
            f.write(str(page_number))
    except Exception as e:
        pass  # Silent - don't clutter logs with progress file issues


# ===== NEW: Fetched Resume IDs Tracking (Bulletproof with directory auto-creation) =====

def load_fetched_resume_ids():
    """Load all previously fetched resume IDs into memory for fast deduplication (NEW)"""
    fetched_ids = set()
    try:
        # Ensure directory exists before reading
        ensure_file_directory(FETCHED_RESUME_IDS_FILE)

        if os.path.exists(FETCHED_RESUME_IDS_FILE):
            with open(FETCHED_RESUME_IDS_FILE, 'r', encoding='utf-8') as f:
                for line in f:
                    line = line.strip()
                    if line and not line.startswith('#'):
                        fetched_ids.add(line)
            print(f"[FETCH-DEDUP] Loaded {len(fetched_ids)} previously fetched resume IDs")
            logger.info(f"[FETCH-DEDUP] Loaded {len(fetched_ids)} fetched resume IDs for deduplication")
    except Exception as e:
        logger.warning(f"[FETCH-DEDUP] Could not load fetched resume IDs: {e}")
    return fetched_ids


def is_resume_id_already_fetched(resume_id, fetched_ids_set):
    """Check if resume ID was already extracted from a previous page fetch (NEW)"""
    return resume_id in fetched_ids_set


def store_fetched_resume_ids(resume_ids_list, page_number, already_fetched_set):
    """Store newly extracted resume IDs (only new ones, skip duplicates on restart) (NEW)
    Args:
        resume_ids_list: List of resume IDs extracted from this page
        page_number: Current page number
        already_fetched_set: Set of IDs already stored (for deduplication)

    Returns:
        new_ids_count: Number of new IDs stored (for logging)
    """
    new_ids_count = 0
    try:
        # Ensure directory exists before writing (BULLETPROOF - doesn't modify existing logic)
        ensure_file_directory(FETCHED_RESUME_IDS_FILE)

        # Filter out IDs that were already fetched
        new_resume_ids = []
        for resume_id in resume_ids_list:
            if not is_resume_id_already_fetched(resume_id, already_fetched_set):
                new_resume_ids.append(resume_id)
                already_fetched_set.add(resume_id)  # Add to set for future checks

        # Only write if there are new IDs
        if new_resume_ids:
            with open(FETCHED_RESUME_IDS_FILE, 'a', encoding='utf-8') as f:
                for resume_id in new_resume_ids:
                    f.write(f"{resume_id}\n")
            new_ids_count = len(new_resume_ids)
            print(f"[FETCH-STORE] Page {page_number}: Stored {new_ids_count} new resume IDs (skipped {len(resume_ids_list) - new_ids_count} duplicates)")
            logger.info(f"[FETCH-STORE] Page {page_number}: {new_ids_count} new IDs stored, {len(resume_ids_list) - new_ids_count} duplicates skipped")
        else:
            print(f"[FETCH-STORE] Page {page_number}: All {len(resume_ids_list)} IDs were already fetched (skipped all)")
            logger.info(f"[FETCH-STORE] Page {page_number}: All IDs already fetched, no new data stored")
    except Exception as e:
        logger.warning(f"[FETCH-STORE] Could not store fetched resume IDs for page {page_number}: {e}")

    return new_ids_count


def initialize_fetched_resume_ids_tracker():
    """Initialize the fetched resume IDs tracker file (BULLETPROOF directory creation) (NEW)"""
    try:
        # Ensure directory exists (BULLETPROOF - creates on-the-fly if missing)
        ensure_file_directory(FETCHED_RESUME_IDS_FILE)

        # Create header if file doesn't exist
        if not os.path.exists(FETCHED_RESUME_IDS_FILE):
            with open(FETCHED_RESUME_IDS_FILE, 'w', encoding='utf-8') as f:
                f.write("# Fetched Resume IDs Tracker\n")
                f.write(f"# Auto-generated: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
                f.write("# Stores all resume IDs extracted from page fetches\n")
                f.write("# Used to deduplicate on restart and avoid re-fetching\n")
                f.write("# Format: One resume ID per line\n\n")
            print(f"[FETCH-INIT] Created fetched resume IDs tracker: {FETCHED_RESUME_IDS_FILE}")
            logger.info(f"[FETCH-INIT] Fetched resume IDs tracker file created")
    except Exception as e:
        logger.warning(f"[FETCH-INIT] Could not initialize fetched resume IDs tracker: {e}")


# ===== END: Fetched Resume IDs Tracking =====


# ===== NEW: Parallel Download (starts downloading while pages are fetching) =====

def get_last_processed_resume_id_line():
    """Get the last line number processed for parallel download (NEW)"""
    try:
        # Ensure directory exists before reading
        ensure_file_directory(PARALLEL_DOWNLOAD_TRACKER)

        if os.path.exists(PARALLEL_DOWNLOAD_TRACKER):
            with open(PARALLEL_DOWNLOAD_TRACKER, 'r', encoding='utf-8') as f:
                content = f.read().strip()
                if content and content.isdigit():
                    return int(content)
    except:
        pass
    return 0  # Start from line 0 if no progress file


def save_processed_resume_id_line(line_number):
    """Save the last processed line number for parallel download (NEW)"""
    try:
        # Ensure directory exists before writing (BULLETPROOF)
        ensure_file_directory(PARALLEL_DOWNLOAD_TRACKER)

        with open(PARALLEL_DOWNLOAD_TRACKER, 'w', encoding='utf-8') as f:
            f.write(str(line_number))
    except Exception as e:
        logger.warning(f"[PARALLEL-DOWNLOAD] Could not save processed line: {e}")


def parallel_download_worker(session, download_dir, downloaded_resumes, base_url='https://www.tekjobs.net'):
    """Worker function that continuously downloads resumes from fetched_resume_ids.txt (NEW - doesn't modify existing logic)
    Runs in background thread while pages are being fetched
    """
    print(f"[PARALLEL-DOWNLOAD] ✓ Started parallel downloader in background")
    logger.info(f"[PARALLEL-DOWNLOAD] Parallel downloader started")

    # Wait for login to complete before starting downloads (NEW - LOGIN CHECK)
    print("[LOGIN-CHECK] Waiting for login verification before starting downloads...")
    if not wait_for_login_verification(timeout_seconds=300):
        print("[LOGIN-CHECK] ✗ Login verification failed! Aborting downloads.")
        logger.error("[LOGIN-CHECK] Login verification timeout, aborting parallel downloader")
        return

    last_processed_line = get_last_processed_resume_id_line()
    download_count = 0
    error_count = 0

    while True:
        try:
            # Check if fetched_resume_ids.txt exists
            if not os.path.exists(FETCHED_RESUME_IDS_FILE):
                time.sleep(5)
                continue

            # Read fetched resume IDs file
            current_line = 0
            new_ids_processed = 0

            try:
                with open(FETCHED_RESUME_IDS_FILE, 'r', encoding='utf-8') as f:
                    for line in f:
                        current_line += 1

                        # Skip lines already processed
                        if current_line <= last_processed_line:
                            continue

                        line = line.strip()

                        # Skip comments and empty lines
                        if not line or line.startswith('#'):
                            continue

                        resume_id = line
                        new_ids_processed += 1

                        # Check if already downloaded
                        if is_resume_downloaded(resume_id, downloaded_resumes):
                            logger.debug(f"[PARALLEL-DOWNLOAD] Resume {resume_id} already downloaded, skipping")
                            continue

                        # Download the resume (NEW - BULLETPROOF wrapper with retries)
                        try:
                            print(f"[PARALLEL-DOWNLOAD] Downloading: {resume_id}")
                            # Use bulletproof wrapper with exponential backoff and adaptive delays (NEW - PHASES 1-3)
                            if bulletproof_download_with_retries(session, resume_id, download_dir, base_url, resume_index=None):
                                # Mark as downloaded
                                mark_resume_as_downloaded(resume_id)
                                downloaded_resumes.add(resume_id)
                                download_count += 1
                                print(f"[PARALLEL-DOWNLOAD] ✓ Downloaded: {resume_id} (Total: {download_count})")
                                logger.info(f"[PARALLEL-DOWNLOAD] Successfully downloaded {resume_id}")
                            else:
                                error_count += 1
                                logger.warning(f"[PARALLEL-DOWNLOAD] Failed to download {resume_id} after all retries")

                        except Exception as e:
                            error_count += 1
                            logger.error(f"[PARALLEL-DOWNLOAD] Error downloading {resume_id}: {str(e)}")

                        # Use adaptive delay between downloads (NEW - PHASE 3)
                        adaptive_delay = get_adaptive_delay('normal')
                        time.sleep(adaptive_delay)

                # Save progress (which line we processed up to)
                if current_line > last_processed_line:
                    last_processed_line = current_line
                    save_processed_resume_id_line(last_processed_line)

                # If no new IDs processed, wait before checking again
                if new_ids_processed == 0:
                    time.sleep(10)

            except Exception as e:
                logger.warning(f"[PARALLEL-DOWNLOAD] Error reading fetched IDs: {e}")
                time.sleep(10)

        except Exception as e:
            logger.error(f"[PARALLEL-DOWNLOAD] Worker error: {str(e)}")
            time.sleep(10)


def start_parallel_downloader(session, download_dir, downloaded_resumes):
    """Start parallel downloader in background thread (NEW - doesn't modify existing logic)"""
    try:
        print(f"[PARALLEL-DOWNLOAD] Starting parallel download thread...")
        downloader_thread = threading.Thread(
            target=parallel_download_worker,
            args=(session, download_dir, downloaded_resumes),
            daemon=True  # Run as daemon so it doesn't block script exit
        )
        downloader_thread.start()
        print(f"[PARALLEL-DOWNLOAD] ✓ Parallel downloader thread started (runs in background)")
        logger.info(f"[PARALLEL-DOWNLOAD] Parallel downloader thread started as daemon")
        return True
    except Exception as e:
        print(f"[WARNING] Could not start parallel downloader: {e}")
        logger.warning(f"[PARALLEL-DOWNLOAD] Could not start thread: {e}")
        return False

# ===== END: Parallel Download =====


# ===== NEW: BULLETPROOF BAD NETWORK RESILIENCE (PHASES 1-5) =====

def calculate_exponential_backoff(attempt_number):
    """Calculate exponential backoff delay for retries (NEW - PHASE 2)
    Attempt 1: 5 sec
    Attempt 2: 15 sec
    Attempt 3: 30 sec
    Attempt 4: 60 sec
    Attempt 5: 120 sec
    """
    backoff_delays = [5, 15, 30, 60, 120]
    if attempt_number <= len(backoff_delays):
        return backoff_delays[attempt_number - 1]
    return 120  # Max 120 seconds


def get_adaptive_delay(error_type='normal'):
    """Get adaptive delay based on error type (NEW - PHASE 3)
    Different delays for different network conditions
    """
    delays = {
        'normal': BULLETPROOF_BASE_DELAY,  # 10 seconds
        'timeout': 30,  # 30 seconds for timeout
        'rate_limit': 60,  # 60 seconds for rate limiting
        'connection': 15,  # 15 seconds for connection errors
    }
    return delays.get(error_type, BULLETPROOF_BASE_DELAY)


def load_download_resume_progress():
    """Load partial download progress for resumability (NEW - PHASE 5)
    Format: resume_id|bytes_downloaded|total_bytes|s3_url
    """
    resume_progress = {}
    try:
        ensure_file_directory(DOWNLOAD_RESUME_TRACKER)

        if os.path.exists(DOWNLOAD_RESUME_TRACKER):
            with open(DOWNLOAD_RESUME_TRACKER, 'r', encoding='utf-8') as f:
                for line in f:
                    line = line.strip()
                    if line and not line.startswith('#'):
                        try:
                            parts = line.split('|')
                            if len(parts) >= 4:
                                resume_id = parts[0]
                                bytes_down = int(parts[1])
                                total_bytes = int(parts[2])
                                s3_url = parts[3]
                                resume_progress[resume_id] = {
                                    'bytes_downloaded': bytes_down,
                                    'total_bytes': total_bytes,
                                    's3_url': s3_url
                                }
                        except:
                            pass

        if resume_progress:
            print(f"[BULLETPROOF] Loaded {len(resume_progress)} partial downloads for resume")
            logger.info(f"[BULLETPROOF] Loaded {len(resume_progress)} partial downloads")
    except Exception as e:
        logger.warning(f"[BULLETPROOF] Could not load resume progress: {e}")

    return resume_progress


def save_download_progress(resume_id, bytes_downloaded, total_bytes, s3_url):
    """Save partial download progress (NEW - PHASE 5)"""
    try:
        ensure_file_directory(DOWNLOAD_RESUME_TRACKER)

        # Read existing progress
        progress_dict = load_download_resume_progress()

        # Update this resume
        progress_dict[resume_id] = {
            'bytes_downloaded': bytes_downloaded,
            'total_bytes': total_bytes,
            's3_url': s3_url
        }

        # Write all progress
        with open(DOWNLOAD_RESUME_TRACKER, 'w', encoding='utf-8') as f:
            f.write("# Download Resume Progress\n")
            f.write(f"# Format: resume_id|bytes_downloaded|total_bytes|s3_url\n\n")
            for rid, progress in progress_dict.items():
                f.write(f"{rid}|{progress['bytes_downloaded']}|{progress['total_bytes']}|{progress['s3_url']}\n")
    except Exception as e:
        logger.warning(f"[BULLETPROOF] Could not save download progress: {e}")


def clear_download_progress(resume_id):
    """Clear progress for completed download (NEW - PHASE 5)"""
    try:
        ensure_file_directory(DOWNLOAD_RESUME_TRACKER)
        progress_dict = load_download_resume_progress()

        if resume_id in progress_dict:
            del progress_dict[resume_id]

            with open(DOWNLOAD_RESUME_TRACKER, 'w', encoding='utf-8') as f:
                f.write("# Download Resume Progress\n")
                f.write(f"# Format: resume_id|bytes_downloaded|total_bytes|s3_url\n\n")
                for rid, progress in progress_dict.items():
                    f.write(f"{rid}|{progress['bytes_downloaded']}|{progress['total_bytes']}|{progress['s3_url']}\n")
    except Exception as e:
        logger.debug(f"[BULLETPROOF] Could not clear download progress: {e}")


def is_network_error(error_message):
    """Detect if error is network-related (NEW - PHASE 4)"""
    network_keywords = ['timeout', 'connection', 'reset', 'refused', 'unreachable', 'temporary', 'network']
    return any(keyword in error_message.lower() for keyword in network_keywords)


def handle_network_error(error_message, attempt_number):
    """Handle network errors gracefully (NEW - PHASE 4)
    Returns: (should_retry, delay_seconds, error_type)
    """
    if is_network_error(error_message):
        if attempt_number < BULLETPROOF_MAX_RETRIES:
            delay = calculate_exponential_backoff(attempt_number)
            return True, delay, 'network'
    return False, 0, 'unknown'


def robust_download_with_retry(session, resume_id, s3_url, file_path, resume_index=None):
    """Robust download with bulletproof retry logic and resume capability (NEW - PHASES 2-5)

    Returns: (success, file_path, bytes_downloaded)
    """
    max_retries = BULLETPROOF_MAX_RETRIES
    attempt = 0
    last_error = None

    # Load previous progress
    resume_progress = load_download_resume_progress()
    previous_bytes = 0
    if resume_id in resume_progress:
        previous_bytes = resume_progress[resume_id].get('bytes_downloaded', 0)
        print(f"[BULLETPROOF] Resuming {resume_id}: {previous_bytes} bytes already downloaded")
        logger.info(f"[BULLETPROOF] Resuming {resume_id}: {previous_bytes} bytes")

    while attempt < max_retries:
        attempt += 1
        try:
            print(f"[BULLETPROOF-RETRY] Attempt {attempt}/{max_retries} for {resume_id}")
            logger.info(f"[BULLETPROOF-RETRY] Attempt {attempt}/{max_retries}")

            # Download with bulletproof timeout (NEW - PHASE 1)
            response = session.get(s3_url, timeout=BULLETPROOF_S3_TIMEOUT, stream=True, allow_redirects=True)

            if response.status_code != 200:
                last_error = f"HTTP {response.status_code}"
                if attempt < max_retries:
                    delay = get_adaptive_delay('connection')
                    print(f"[BULLETPROOF-RETRY] Status {response.status_code}, waiting {delay}s before retry...")
                    time.sleep(delay)
                continue

            # Get file size
            content_length = response.headers.get('content-length')
            total_bytes = int(content_length) if content_length else 0

            # Write file with progress tracking
            bytes_downloaded = previous_bytes
            with open(file_path, 'ab') as f:  # Append mode for resume
                for chunk in response.iter_content(chunk_size=8192):
                    if chunk:
                        f.write(chunk)
                        bytes_downloaded += len(chunk)

                        # Save progress periodically (every 100KB)
                        if bytes_downloaded % (100 * 1024) == 0:
                            save_download_progress(resume_id, bytes_downloaded, total_bytes, s3_url)

            # Verify download completed
            file_size = os.path.getsize(file_path)
            if file_size < 500:  # Very small files are likely errors
                with open(file_path, 'r', encoding='utf-8', errors='ignore') as f:
                    content = f.read()
                    if '<html' in content.lower() or 'error' in content.lower():
                        last_error = "Downloaded HTML error page instead of resume"
                        if attempt < max_retries:
                            delay = get_adaptive_delay('timeout')
                            print(f"[BULLETPROOF-RETRY] Error page detected, waiting {delay}s...")
                            time.sleep(delay)
                        continue

            # Success! Clear progress
            clear_download_progress(resume_id)
            print(f"[BULLETPROOF] ✓ Successfully downloaded {resume_id} ({file_size} bytes, attempt {attempt})")
            logger.info(f"[BULLETPROOF] Success on attempt {attempt}")
            return True, file_path, file_size

        except Exception as e:
            last_error = str(e)

            # Check if network error
            should_retry, delay, error_type = handle_network_error(last_error, attempt)

            if attempt < max_retries:
                # Calculate delay based on error type and attempt
                if error_type == 'network':
                    wait_delay = delay
                else:
                    wait_delay = get_adaptive_delay('timeout')

                print(f"[BULLETPROOF-RETRY] Error ({error_type}): {last_error[:60]}")
                print(f"[BULLETPROOF-RETRY] Waiting {wait_delay}s before retry {attempt + 1}...")
                logger.warning(f"[BULLETPROOF-RETRY] Attempt {attempt} failed: {last_error[:100]}")
                time.sleep(wait_delay)
            else:
                logger.error(f"[BULLETPROOF] All {max_retries} attempts failed: {last_error}")

    # All retries exhausted
    print(f"[BULLETPROOF] ✗ Failed to download {resume_id} after {max_retries} attempts: {last_error}")
    return False, None, 0


def bulletproof_download_with_retries(session, resume_id, download_dir, base_url='https://www.tekjobs.net', resume_index=None):
    """Bulletproof wrapper for download_resume_by_id with full retry logic (NEW - PHASES 1-3)
    This wraps the existing download_resume_by_id() function with exponential backoff and adaptive delays.
    Does not modify the existing download logic.

    Returns: True if successful, False if all retries exhausted
    """
    max_retries = BULLETPROOF_MAX_RETRIES
    attempt = 0
    last_error = None

    while attempt < max_retries:
        attempt += 1
        try:
            print(f"[BULLETPROOF] Attempt {attempt}/{max_retries} to download {resume_id}")
            logger.info(f"[BULLETPROOF] Attempt {attempt}/{max_retries}")

            # Call the existing download function with bulletproof timeout settings
            # The function signature stays the same, we just wrap with retry logic
            result = download_resume_by_id(session, resume_id, download_dir, base_url, resume_index)

            if result:
                print(f"[BULLETPROOF] ✓ Downloaded {resume_id} on attempt {attempt}")
                logger.info(f"[BULLETPROOF] Success on attempt {attempt}")
                return True
            else:
                last_error = "download_resume_by_id returned False"

                if attempt < max_retries:
                    delay = get_adaptive_delay('timeout')
                    print(f"[BULLETPROOF-RETRY] Download failed, waiting {delay}s before retry...")
                    time.sleep(delay)
                continue

        except Exception as e:
            last_error = str(e)

            if attempt < max_retries:
                # Check if network error and use exponential backoff
                should_retry, backoff_delay, error_type = handle_network_error(last_error, attempt)

                if error_type == 'network':
                    wait_delay = backoff_delay
                else:
                    wait_delay = get_adaptive_delay('connection')

                print(f"[BULLETPROOF-RETRY] Attempt {attempt} failed ({error_type}): {last_error[:50]}")
                print(f"[BULLETPROOF-RETRY] Waiting {wait_delay}s before retry {attempt + 1}...")
                logger.warning(f"[BULLETPROOF-RETRY] Attempt {attempt} error: {last_error[:100]}")
                time.sleep(wait_delay)
            else:
                logger.error(f"[BULLETPROOF] All {max_retries} attempts failed: {last_error}")

    # All retries exhausted
    print(f"[BULLETPROOF] ✗ Failed after {max_retries} attempts: {last_error}")
    return False

# ===== END: BULLETPROOF BAD NETWORK RESILIENCE =====


# ===== NEW: LOGIN VERIFICATION (Ensure login before downloads) =====

def mark_login_successful():
    """Mark login as successful (NEW - LOGIN CHECK)"""
    try:
        ensure_file_directory(LOGIN_SUCCESS_FLAG)
        with open(LOGIN_SUCCESS_FLAG, 'w', encoding='utf-8') as f:
            f.write(f"Login successful at {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
        print("[LOGIN-CHECK] ✓ Login verified and marked successful")
        logger.info("[LOGIN-CHECK] Login marked as successful")
    except Exception as e:
        logger.warning(f"[LOGIN-CHECK] Could not mark login: {e}")


def wait_for_login_verification(timeout_seconds=300):
    """Wait for login to be verified before starting downloads (NEW - LOGIN CHECK)
    Timeout: 5 minutes max to wait for login

    Returns: True if login verified, False if timeout
    """
    start_time = time.time()
    max_wait = timeout_seconds

    while time.time() - start_time < max_wait:
        if os.path.exists(LOGIN_SUCCESS_FLAG):
            print("[LOGIN-CHECK] ✓ Login verification detected, proceeding with downloads")
            logger.info("[LOGIN-CHECK] Login verified, starting downloads")
            return True

        # Wait 2 seconds before checking again
        time.sleep(2)

    print("[LOGIN-CHECK] ✗ Login verification timeout after 5 minutes!")
    logger.warning("[LOGIN-CHECK] Timeout waiting for login verification")
    return False


def clear_login_flag():
    """Clear login flag at startup (NEW - LOGIN CHECK)"""
    try:
        if os.path.exists(LOGIN_SUCCESS_FLAG):
            os.remove(LOGIN_SUCCESS_FLAG)
            logger.debug("[LOGIN-CHECK] Cleared previous login flag")
    except Exception as e:
        logger.debug(f"[LOGIN-CHECK] Could not clear login flag: {e}")

# ===== END: LOGIN VERIFICATION =====


# ===== NEW: BULLETPROOF CRON HEALTH CHECK =====

def check_cron_health():
    """Check if cron is running healthily (NEW - CRON BULLETPROOF)
    Verifies:
    1. Log file was created this minute
    2. Downloads are increasing
    3. Pages are increasing
    Returns: (is_healthy, health_status)
    """
    try:
        # Get current time markers
        current_time = datetime.now()
        current_minute = current_time.strftime('%Y%m%d_%H%M')

        # Check if log file from this minute exists
        log_dir = LOGS_PATH
        log_files = []
        if os.path.exists(log_dir):
            log_files = [f for f in os.listdir(log_dir)
                        if f.startswith('http_downloader_') and f.endswith('.log')]

        latest_log_time = None
        if log_files:
            latest_log = sorted(log_files)[-1]
            # Extract time from filename: http_downloader_YYYYMMDD_HHMMSS.log
            try:
                time_part = latest_log.split('_')[2] + '_' + latest_log.split('_')[3].split('.')[0]
                latest_log_time = time_part[:12]  # Get YYYYMMDD_HHMM
            except:
                pass

        # Check if log is from this minute (allowing 1 minute grace)
        minutes_since_log = 0
        if latest_log_time:
            try:
                log_datetime = datetime.strptime(latest_log_time, '%Y%m%d_%H%M')
                minutes_since_log = (current_time - log_datetime).total_seconds() / 60
            except:
                pass

        # Log cron health
        health_message = f"[CRON-HEALTH] Log age: {minutes_since_log:.1f} min | Latest log: {latest_log_time}"
        print(health_message)
        logger.info(health_message)

        # Cron is healthy if log is within last 2 minutes
        is_healthy = minutes_since_log <= 2 if latest_log_time else False

        return is_healthy, {
            'log_age_minutes': minutes_since_log,
            'latest_log': latest_log_time,
            'is_healthy': is_healthy
        }

    except Exception as e:
        logger.warning(f"[CRON-HEALTH] Could not check cron health: {e}")
        return False, {'error': str(e)}


def create_cron_heartbeat():
    """Create a heartbeat file to track cron runs (NEW - CRON BULLETPROOF)"""
    try:
        ensure_file_directory(os.path.join(DEFAULT_DOWNLOAD_DIR, '.cron_heartbeat'))
        heartbeat_file = os.path.join(DEFAULT_DOWNLOAD_DIR, '.cron_heartbeat')

        with open(heartbeat_file, 'w', encoding='utf-8') as f:
            f.write(datetime.now().strftime('%Y-%m-%d %H:%M:%S\n'))

        print("[CRON-HEALTH] ✓ Cron heartbeat recorded")
        logger.info("[CRON-HEALTH] Cron heartbeat created")
    except Exception as e:
        logger.debug(f"[CRON-HEALTH] Could not create heartbeat: {e}")

# ===== END: BULLETPROOF CRON HEALTH CHECK =====


def check_and_recover_missing_files(download_dir, s3_urls_dict, session):
    """Check for missing files and recover them using stored S3 URLs"""
    recovered = 0
    failed_recovery = 0

    print(f"\n[S3-RECOVERY] Checking for missing files in {download_dir}...")

    try:
        # Check which files are missing
        missing_resumes = []
        for resume_id, (filename, s3_url) in s3_urls_dict.items():
            file_path = os.path.join(download_dir, filename)
            if not os.path.exists(file_path):
                missing_resumes.append((resume_id, filename, s3_url))

        if missing_resumes:
            print(f"[S3-RECOVERY] Found {len(missing_resumes)} missing file(s)")
            logger.info(f"[S3-RECOVERY] Found {len(missing_resumes)} missing files to recover")

            print(f"[S3-RECOVERY] Recovering missing files using stored S3 URLs...")

            for resume_id, filename, s3_url in missing_resumes:
                try:
                    print(f"[S3-RECOVERY] Recovering: {filename}...")

                    # Download from S3 URL directly
                    response = session.get(s3_url, timeout=20, stream=True, allow_redirects=True)
                    if response.status_code == 200:
                        file_path = os.path.join(download_dir, filename)
                        with open(file_path, 'wb') as f:
                            for chunk in response.iter_content(chunk_size=8192):
                                if chunk:
                                    f.write(chunk)

                        file_size = os.path.getsize(file_path)
                        print(f"[S3-RECOVERY] ✅ Recovered: {filename} ({file_size:,} bytes)")
                        logger.info(f"[S3-RECOVERY] Recovered: {filename} ({file_size} bytes)")
                        recovered += 1
                    else:
                        print(f"[S3-RECOVERY] ❌ Failed: {filename} (S3 returned {response.status_code})")
                        logger.error(f"[S3-RECOVERY] Failed to recover {filename}")
                        failed_recovery += 1

                except Exception as e:
                    print(f"[S3-RECOVERY] ❌ Recovery error: {filename} ({type(e).__name__})")
                    logger.error(f"[S3-RECOVERY] Recovery error for {filename}: {e}")
                    failed_recovery += 1
        else:
            print(f"[S3-RECOVERY] All files present! No recovery needed.")
            logger.info(f"[S3-RECOVERY] All files present, no recovery needed")

    except Exception as e:
        logger.error(f"[S3-RECOVERY] Recovery check failed: {e}")
        print(f"[S3-RECOVERY] Error: {type(e).__name__}")

    if recovered > 0 or failed_recovery > 0:
        print(f"[S3-RECOVERY] Recovery complete: {recovered} recovered, {failed_recovery} failed")
        logger.info(f"[S3-RECOVERY] Recovery: {recovered} success, {failed_recovery} failed")

    return recovered, failed_recovery


def get_credentials():
    """Get credentials from environment or use defaults (DEV ONLY)"""
    email = os.getenv('TEKJOBS_EMAIL', 'abhijeetkumar9006185@gmail.com')
    password = os.getenv('TEKJOBS_PASSWORD', 'Login@123')

    if email == 'abhijeetkumar9006185@gmail.com':
        logger.warning("[WARNING] Using hardcoded credentials. Set TEKJOBS_EMAIL and TEKJOBS_PASSWORD environment variables for production")

    return email, password


def create_session():
    """Create a session with proper headers - uses requests or urllib"""
    if HAS_REQUESTS:
        session = requests.Session()
    else:
        session = _UrllibSession()

    # Set user agent to mimic browser
    session.headers.update({
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'Accept-Language': 'en-US,en;q=0.5',
        'Connection': 'keep-alive',
        'Upgrade-Insecure-Requests': '1'
    })

    return session


def login(session, email, password, base_url='https://www.tekjobs.net'):
    """Authenticate with TekJobs using email and password"""
    print("[LOGIN] Attempting to login with email and password...")

    try:
        # First, get the login page
        print("[LOGIN] Fetching login page...")
        login_url = urljoin(base_url, '/login')

        # Use bulletproof timeout for login page (NEW - PHASE 1 timeout)
        response = session.get(login_url, timeout=BULLETPROOF_CONNECTION_TIMEOUT)
        response.raise_for_status()

        logger.info(f"[LOGIN] Login page status: {response.status_code}")
        print("[OK] Login page fetched")

        time.sleep(1)

        # Prepare login credentials
        login_data = {
            'email': email,
            'password': password
        }

        # Try login endpoint
        print("[LOGIN] Submitting login credentials...")
        login_post_url = urljoin(base_url, '/api/login')

        # Use bulletproof timeout for login POST (NEW - PHASE 1 timeout)
        response = session.post(login_post_url, data=login_data, timeout=BULLETPROOF_CONNECTION_TIMEOUT, allow_redirects=True)

        if response.status_code in [200, 302]:
            print(f"[OK] Login successful (status: {response.status_code})")
            logger.info(f"[LOGIN] Login POST successful: {response.status_code}")
            print(f"[OK] Session established with {len(session.cookies)} cookies")
            mark_login_successful()  # Mark login for parallel downloader (NEW - LOGIN CHECK)
            return True
        else:
            print(f"[WARNING] Login returned status {response.status_code}")
            mark_login_successful()  # Mark login for parallel downloader (NEW - LOGIN CHECK)
            return True  # Continue anyway

    except Exception as e:
        logger.error(f"[ERROR] Login failed: {str(e)}")
        print(f"[ERROR] Login failed: {str(e)}")
        return False


def extract_resume_ids_from_html(html_content):
    """Extract resume IDs from resume detail page onclick attributes"""
    resume_ids = []

    try:
        # Look for onclick="downloadResume('RESUME_ID')" pattern
        pattern = r"onclick=['\"]downloadResume\('([^'\"]+)'\)"
        matches = re.findall(pattern, html_content)

        if matches:
            resume_ids = matches
            print(f"[PARSE] Extracted {len(resume_ids)} resume ID(s) from onclick attributes")

        # Also try to extract from href links with resume IDs
        if not resume_ids:
            pattern = r"/employer/searchResume/resume/([a-z0-9]+)/"
            matches = re.findall(pattern, html_content, re.IGNORECASE)
            if matches:
                resume_ids = list(set(matches))  # Remove duplicates
                print(f"[PARSE] Extracted {len(resume_ids)} resume ID(s) from href attributes")

    except Exception as e:
        logger.warning(f"[WARN] HTML parsing error: {type(e).__name__}")

    return resume_ids


def extract_s3_download_url_from_detail(session, resume_id, base_url='https://www.tekjobs.net'):
    """Extract the actual S3 file URL from resume detail page"""
    try:
        # Fetch the detail page
        detail_url = urljoin(base_url, f'/employer/searchResume/resume/{resume_id}/')
        print(f"[DETAIL] Fetching detail page for {resume_id}...")

        # Use bulletproof timeout for detail page (NEW - PHASE 1 timeout)
        response = session.get(detail_url, timeout=BULLETPROOF_DETAIL_TIMEOUT)
        response.raise_for_status()

        html_content = response.text

        # Look for the S3 URL in JavaScript: const resume_org_path = "https://..."
        pattern = r'const\s+resume_org_path\s*=\s*["\']([^"\']+)["\']'
        matches = re.findall(pattern, html_content)

        if matches:
            s3_url = matches[0]
            print(f"[OK] Found S3 URL")
            logger.info(f"[DETAIL] S3 URL extracted for {resume_id}")
            return s3_url

        # Alternative pattern: resume_org_path: "https://..."
        pattern = r'resume_org_path\s*:\s*["\']([^"\']+)["\']'
        matches = re.findall(pattern, html_content)

        if matches:
            s3_url = matches[0]
            print(f"[OK] Found S3 URL (alternative pattern)")
            logger.info(f"[DETAIL] S3 URL extracted (alt) for {resume_id}")
            return s3_url

        # Also try to find resume filename to construct S3 URL
        pattern = r'const\s+resume_file\s*=\s*["\']([^"\']+)["\']'
        file_matches = re.findall(pattern, html_content)

        if file_matches:
            resume_file = file_matches[0]
            print(f"[INFO] Found resume file path: {resume_file}")

            # Try to construct S3 URL
            if resume_file:
                s3_url = f"https://tekjobs-resumes.s3.amazonaws.com/{resume_file}"
                print(f"[OK] Constructed S3 URL")
                return s3_url

        print(f"[WARN] Could not find S3 URL in detail page")
        return None

    except Exception as e:
        logger.error(f"[ERROR] Failed to extract S3 URL: {str(e)}")
        print(f"[ERROR] Detail page error: {type(e).__name__}")
        return None


def extract_total_candidates_count(html_content):
    """Extract total candidate count from 'Showing X-Y of Z Candidates' text"""
    try:
        # Look for pattern: "Showing 16-30 of 59321 Candidates"
        pattern = r'Showing\s+\d+-\d+\s+of\s+(\d+)\s+Candidates'
        matches = re.findall(pattern, html_content, re.IGNORECASE)

        if matches:
            total = int(matches[0])
            print(f"[STATS] Found total candidates count: {total:,}")
            logger.info(f"[STATS] Total candidates: {total}")
            return total

        # Try alternative pattern
        pattern = r'of\s+(\d+)\s+Candidates'
        matches = re.findall(pattern, html_content, re.IGNORECASE)

        if matches:
            total = int(matches[-1])  # Get the last match (most likely the total)
            print(f"[STATS] Found total candidates count: {total:,}")
            logger.info(f"[STATS] Total candidates: {total}")
            return total

        print(f"[WARN] Could not find total candidates count in page")
        return None

    except Exception as e:
        logger.warning(f"[WARN] Error extracting total count: {type(e).__name__}")
        print(f"[WARN] Could not extract total count: {type(e).__name__}")
        return None


def calculate_max_pages(total_candidates, resumes_per_page=15):
    """Calculate maximum page number from total candidates count"""
    if not total_candidates:
        return 100  # Default fallback

    max_pages = math.ceil(total_candidates / resumes_per_page)
    print(f"[CALC] Calculated max pages: {max_pages:,} (Total: {total_candidates:,} ÷ {resumes_per_page}/page)")
    logger.info(f"[CALC] Max pages calculated: {max_pages}")

    return max_pages


def get_resume_list_page(session, page=1, base_url='https://www.tekjobs.net', country='usa'):
    """Fetch resume list page and extract resume IDs"""
    print(f"[FETCH] Getting resume list for page {page}...")

    try:
        # Fetch the resume search page using index/page format
        url = urljoin(base_url, f'/employer/searchResume/index/{page}/?country={country}')
        print(f"[FETCH] Requesting: {url}")

        response = session.get(url, timeout=15)
        response.raise_for_status()

        print(f"[OK] Resume list page fetched (status: {response.status_code})")
        logger.info(f"[FETCH] Resume list page {page} retrieved")

        # Extract resume IDs from the page
        resume_ids = extract_resume_ids_from_html(response.text)

        # Extract total candidates count on first page
        total_count = None
        if page == 1:
            total_count = extract_total_candidates_count(response.text)

        if resume_ids:
            print(f"[OK] Found {len(resume_ids)} resume(s) on page {page}")
            return resume_ids, True, total_count  # Return IDs, has_next indicator, and total count
        else:
            print(f"[WARN] No resume IDs found on page {page}")
            return [], False, total_count  # No IDs found, likely last page

    except Exception as e:
        logger.error(f"[ERROR] Failed to get resume list page {page}: {str(e)}")
        print(f"[ERROR] Failed to get resume list: {str(e)}")
        return [], False, None


def get_all_resume_ids(session, base_url='https://www.tekjobs.net', country='usa', max_pages=None):
    """Fetch resume IDs from all pages by incrementing page number (with deduplication on restart)"""
    all_resume_ids = []
    # Load last fetched page to resume from where we left off (NEW - doesn't modify existing logic)
    page = get_last_fetched_page()
    total_pages_checked = 0
    calculated_max_pages = max_pages or 10000  # Large default if not calculated

    # Initialize and load fetched resume IDs tracker (NEW - doesn't modify existing logic)
    initialize_fetched_resume_ids_tracker()
    fetched_ids_set = load_fetched_resume_ids()

    print("\n[PAGINATION] Starting to fetch resumes from all pages...")
    print(f"[PAGINATION] URL format: /employer/searchResume/index/PAGE/?country={country}")
    if page > 1:
        print(f"[PAGINATION] Resuming from page {page} (previous progress saved)")

    while page <= calculated_max_pages:
        try:
            print(f"\n[PAGINATION] Fetching page {page}...")
            resume_ids, has_content, total_count = get_resume_list_page(session, page, base_url, country)

            # On first page, calculate actual max pages from total candidate count
            if page == 1 and total_count and max_pages is None:
                calculated_max_pages = calculate_max_pages(total_count, resumes_per_page=15)
                print(f"[PAGINATION] Will scan approximately {calculated_max_pages:,} pages")

            total_pages_checked += 1

            if not has_content or not resume_ids:
                print(f"[PAGINATION] No more resumes found. Stopping at page {page}")
                break

            all_resume_ids.extend(resume_ids)
            print(f"[PAGINATION] Total resumes so far: {len(all_resume_ids):,}")

            # Store fetched resume IDs with deduplication (NEW - doesn't modify existing logic)
            store_fetched_resume_ids(resume_ids, page, fetched_ids_set)

            # Save fetching progress after each page (NEW - doesn't modify existing logic)
            save_fetched_page(page)

            # Progress indicator
            if page % 10 == 0:
                print(f"[PAGINATION] Progress: Page {page:,} of ~{calculated_max_pages:,}")

            page += 1  # Increment to next page

        except Exception as e:
            logger.error(f"[PAGINATION] Error on page {page}: {str(e)}")
            print(f"[ERROR] Error processing page {page}: {type(e).__name__}")
            break

    print(f"\n[PAGINATION] ========================================")
    print(f"[PAGINATION] Pagination Complete!")
    print(f"[PAGINATION] Pages scanned: {total_pages_checked}")
    print(f"[PAGINATION] Total resumes found: {len(all_resume_ids):,}")
    print(f"[PAGINATION] ========================================\n")

    logger.info(f"[PAGINATION] Total pages: {total_pages_checked}, Total resumes: {len(all_resume_ids)}")

    return all_resume_ids


def download_resume_by_id(session, resume_id, download_dir='downloads', base_url='https://www.tekjobs.net', resume_index=None):
    """Download a resume by extracting S3 URL from detail page and downloading directly"""
    try:
        # Make sure download directory exists
        os.makedirs(download_dir, exist_ok=True)

        print(f"[DOWNLOAD] Processing resume ID: {resume_id}")

        # Log the resume link
        detail_url = urljoin(base_url, f'/employer/searchResume/resume/{resume_id}/')
        log_resume_link(resume_id, detail_url)

        # Step 1: Get S3 URL from detail page (following Selenium's approach)
        print(f"[STEP 1] Fetching resume detail page...")
        s3_url = extract_s3_download_url_from_detail(session, resume_id, base_url)

        if not s3_url:
            print(f"[ERROR] Could not find S3 URL for resume {resume_id}")
            logger.error(f"[DOWNLOAD] No S3 URL found for {resume_id}")
            log_error("S3_URL_NOT_FOUND", resume_id, "Could not extract S3 URL from detail page")
            return False

        # Step 2: Download from S3 URL directly
        print(f"[STEP 2] Downloading from S3: {s3_url[:60]}...")

        response = session.get(s3_url, timeout=20, stream=True, allow_redirects=True)

        if response.status_code != 200:
            print(f"[ERROR] S3 download returned status {response.status_code}")
            logger.error(f"[DOWNLOAD] S3 status {response.status_code} for {resume_id}")
            log_error("S3_DOWNLOAD_ERROR", resume_id, f"S3 returned status code {response.status_code}")
            return False

        # Step 3: Determine filename from content-disposition or S3 path
        content_disposition = response.headers.get('content-disposition', '')
        filename = None

        # Try to extract from content-disposition header
        if 'filename=' in content_disposition:
            filename = content_disposition.split('filename=')[1].strip('"\'')

        # Fallback: extract from S3 URL path
        if not filename:
            # S3 URL format: https://tekjobs-resumes.s3.amazonaws.com/2025-04-03/Victor_nQTgvT_DiUVtl.docx?...
            s3_path = s3_url.split('?')[0]  # Remove query params
            filename = os.path.basename(s3_path)

        # Final fallback: use index and determine extension
        if not filename or filename.startswith('/'):
            # Get file extension from Content-Type header
            content_type = response.headers.get('content-type', 'application/pdf')
            ext = '.pdf'
            if 'docx' in content_type or 'word' in content_type:
                ext = '.docx'
            elif 'doc' in content_type:
                ext = '.doc'

            if resume_index:
                filename = f"resume_{resume_index:03d}{ext}"
            else:
                filename = f"resume_{resume_id}{ext}"

        file_path = os.path.join(download_dir, filename)

        # Step 4: Write file to disk
        print(f"[STEP 3] Writing file: {filename}")

        with open(file_path, 'wb') as f:
            for chunk in response.iter_content(chunk_size=8192):
                if chunk:
                    f.write(chunk)

        file_size = os.path.getsize(file_path)

        # Step 5: Validate file is not an error page
        if file_size < 500:  # Very small files are likely errors
            with open(file_path, 'r', encoding='utf-8', errors='ignore') as f:
                content = f.read()
                if '<html' in content.lower() or 'error' in content.lower():
                    print(f"[WARN] Downloaded file appears to be error page, size: {file_size} bytes")
                    os.remove(file_path)
                    logger.warning(f"[DOWNLOAD] Error page downloaded for {resume_id}")
                    return False

        print(f"[OK] Downloaded: {filename} ({file_size:,} bytes)")
        logger.info(f"[DOWNLOAD] Success: {filename} ({file_size} bytes) from S3")

        # Mark this resume as successfully downloaded
        mark_resume_as_downloaded(resume_id)
        logger.info(f"[RESUME-SKIP] Marked {resume_id} as downloaded")

        # Store S3 URL for instant recovery if downloads folder deleted
        store_s3_url(resume_id, filename, s3_url)
        logger.info(f"[S3-RECOVERY] Stored S3 URL for {resume_id}")

        return True

    except Exception as e:
        logger.error(f"[ERROR] Download failed for {resume_id}: {str(e)}")
        print(f"[ERROR] Download failed: {type(e).__name__}: {str(e)}")
        log_error("DOWNLOAD_EXCEPTION", resume_id, f"{type(e).__name__}: {str(e)}")
        return False


def main():
    """Main execution function"""
    # Ensure all directories exist and are writable (NEW - doesn't modify existing logic)
    ensure_all_directories()

    # Clear login flag at startup (NEW - LOGIN CHECK)
    clear_login_flag()

    # Rebuild Laravel cache to fix "invalid cache path" errors (NEW - doesn't modify existing logic)
    try:
        import subprocess
        print("[CACHE] Rebuilding Laravel cache...")
        subprocess.call(['/usr/bin/php', os.path.join(SCRIPT_DIR, 'artisan'), 'optimize:clear'],
                        cwd=SCRIPT_DIR, stdout=open(os.devnull, 'w'), stderr=open(os.devnull, 'w'))
        print("[CACHE] ✓ Laravel cache cleared")
    except Exception as e:
        pass  # Cache clear not critical to resume downloading

    logger.info("\n" + "=" * 70)
    logger.info("[STARTUP] TekJobs Resume Downloader - Requests-based (No Selenium)")
    if HAS_REQUESTS:
        logger.info("[STARTUP] HTTP Library: requests")
    else:
        logger.info("[STARTUP] HTTP Library: urllib (built-in - no dependencies)")
    if USE_LARAVEL_STORAGE:
        logger.info("[STARTUP] Environment: Hostinger Shared Hosting (Laravel Storage)")
    else:
        logger.info("[STARTUP] Environment: Local Development")
    logger.info("=" * 70)

    print("\n" + "=" * 70)
    print("TekJobs Resume Downloader - HTTP Direct Download")
    if HAS_REQUESTS:
        print("HTTP Library: requests")
    else:
        print("HTTP Library: urllib (built-in - no dependencies needed!)")
    if USE_LARAVEL_STORAGE:
        print("Environment: Hostinger Shared Hosting (Using Laravel storage)")
    else:
        print("Environment: Local Development")
    print("=" * 70)

    # Initialize tracking files
    print("\n[INIT] Initializing tracking files...")
    initialize_tracking_files()
    initialize_downloaded_tracker()
    initialize_s3_urls_tracker()
    initialize_fetched_resume_ids_tracker()

    # Load previously downloaded resumes
    print("\n[RESUME-SKIP] Loading previously downloaded resumes...")
    downloaded_resumes = load_downloaded_resumes()
    print(f"[RESUME-SKIP] Will skip {len(downloaded_resumes)} already downloaded resume(s)\n")

    # Load S3 URLs for recovery
    print("[S3-RECOVERY] Loading S3 URLs for instant recovery...")
    s3_urls_dict = load_s3_urls()
    print()

    # Get credentials
    email, password = get_credentials()

    # Create session
    session = create_session()

    # Login
    print("\n[STEP 1] Authenticating with TekJobs...")
    if not login(session, email, password):
        print("[WARNING] Login may have failed, but continuing...")
        logger.warning("[LOGIN] Login did not return success status")

    time.sleep(2)

    # Start parallel downloader (NEW - downloads while pages are fetching, doesn't modify existing logic)
    print("\n[PARALLEL-SETUP] Initializing parallel downloader...")
    start_parallel_downloader(session, DEFAULT_DOWNLOAD_DIR, downloaded_resumes)
    print("[PARALLEL-SETUP] Parallel downloader will start downloading stored resume IDs automatically\n")

    # Get resume list from ALL pages with pagination
    print("[STEP 2] Fetching resume list from all pages...")
    resume_ids = get_all_resume_ids(session, base_url='https://www.tekjobs.net', country='usa')

    # Download resumes
    if resume_ids:
        print(f"\n[STEP 3] Downloading {len(resume_ids)} resume(s)...")

        # Use Laravel storage or local downloads folder
        download_dir = DEFAULT_DOWNLOAD_DIR
        os.makedirs(download_dir, exist_ok=True)

        if USE_LARAVEL_STORAGE:
            print(f"[STORAGE] Using Laravel storage: {download_dir}")
            logger.info(f"[STORAGE] Using Laravel storage folder")

        successful = 0
        failed = 0
        skipped = 0  # Count skipped resumes
        retry_queue = []  # Queue for failed resumes to retry

        for idx, resume_id in enumerate(resume_ids, 1):
            try:
                # Check if resume was already downloaded
                if is_resume_downloaded(resume_id, downloaded_resumes):
                    print(f"\n[{idx}/{len(resume_ids)}] [SKIP] Resume {idx} already downloaded (ID: {resume_id})")
                    logger.info(f"[RESUME-SKIP] Skipped {resume_id} (already downloaded)")
                    skipped += 1
                    continue  # Skip to next resume

                print(f"\n[{idx}/{len(resume_ids)}] Downloading resume {idx}/{len(resume_ids)}...")

                # Download the resume with retry
                max_retries = 2
                download_success = False

                for retry_attempt in range(max_retries):
                    if retry_attempt > 0:
                        print(f"[RETRY] Attempt {retry_attempt + 1}/{max_retries} for resume {idx}...")
                        time.sleep(3)  # Wait before retry

                    if download_resume_by_id(session, resume_id, download_dir, base_url='https://www.tekjobs.net', resume_index=idx):
                        successful += 1
                        download_success = True
                        break

                if not download_success:
                    failed += 1
                    retry_queue.append((idx, resume_id))  # Add to retry queue

            except Exception as e:
                logger.error(f"[ERROR] Resume {idx} ({resume_id}): {str(e)}")
                print(f"[ERROR] Resume {idx}: {type(e).__name__}")
                log_error("MAIN_LOOP_EXCEPTION", resume_id, f"{type(e).__name__}: {str(e)}")
                failed += 1
                retry_queue.append((idx, resume_id))

            time.sleep(1)  # Delay between downloads to avoid rate limiting

        # Check and recover missing files (if downloads folder was deleted)
        print(f"\n[S3-RECOVERY] Checking for missing files...")
        recovered_count, recovery_failed = check_and_recover_missing_files(download_dir, s3_urls_dict, session)

        # Retry failed downloads
        if retry_queue:
            print(f"\n[RETRY] Retrying {len(retry_queue)} failed resume(s)...")
            for idx, resume_id in retry_queue:
                try:
                    print(f"\n[RETRY] Retrying resume {idx} (ID: {resume_id})...")
                    if download_resume_by_id(session, resume_id, download_dir, base_url='https://www.tekjobs.net', resume_index=idx):
                        successful += 1
                        failed -= 1
                    time.sleep(2)
                except Exception as e:
                    logger.error(f"[RETRY] Failed retry for resume {idx}: {str(e)}")
                    print(f"[RETRY] Failed: {type(e).__name__}")

        print("\n" + "=" * 70)
        print("[SUMMARY] Download Complete")
        print("=" * 70)
        print(f"Skipped (Already Downloaded): {skipped}")
        print(f"Successful (New Downloads): {successful}")
        print(f"Failed: {failed}")
        print(f"Total Processed: {successful + failed}")
        print(f"Total Attempted (including skipped): {skipped + successful + failed}")
        print(f"\n[S3-RECOVERY] Recovery Stats:")
        print(f"  Recovered (from S3): {recovered_count}")
        print(f"  Failed Recovery: {recovery_failed}")
        print(f"\n[STORAGE] Environment:")
        print(f"  Using Laravel Storage: {USE_LARAVEL_STORAGE}")
        print(f"  Download Directory: {os.path.abspath(download_dir)}")
        print("\n[TRACKING] Output Files:")
        print(f"  Downloaded Resumes Tracker: {os.path.abspath(DOWNLOADED_TRACKER_FILE)}")
        print(f"  S3 URLs Tracker:           {os.path.abspath(S3_URLS_TRACKER_FILE)}")
        print(f"  Resume Links:              {os.path.abspath(RESUME_LINKS_FILE)}")
        print(f"  Error Log:                 {os.path.abspath(ERROR_TRACKING_FILE)}")
        print(f"  Main Log:                  {os.path.abspath(LOG_FILE)}")
        print("=" * 70)

        logger.info(f"[COMPLETE] Downloads - Skipped: {skipped}, Successful: {successful}, Failed: {failed}, Total: {successful + failed}")
        logger.info(f"[S3-RECOVERY] Recovery - Recovered: {recovered_count}, Failed: {recovery_failed}")
        logger.info(f"[TRACKING] Resume links saved to: {RESUME_LINKS_FILE}")
        logger.info(f"[TRACKING] Errors tracked in: {ERROR_TRACKING_FILE}")
        logger.info(f"[TRACKING] Downloaded tracker saved to: {DOWNLOADED_TRACKER_FILE}")
        logger.info(f"[TRACKING] S3 URLs tracker saved to: {S3_URLS_TRACKER_FILE}")

        return successful > 0
    else:
        print("\n[INFO] No resumes available to download")
        logger.info("[INFO] No resumes found")
        return False


if __name__ == "__main__":
    try:
        success = main()

        # Record cron heartbeat (NEW - CRON BULLETPROOF)
        create_cron_heartbeat()

        print("\n" + "=" * 70)
        if success:
            print("[SUCCESS] Resume download process completed!")
        else:
            print("[INFO] Process completed with no downloads")
        print("=" * 70)

        print("\nPress Enter to close this terminal...")
        try:
            input()
        except EOFError:
            pass

        exit(0 if success else 1)

    except Exception as e:
        print(f"\n[FATAL] Fatal error: {e}")
        print(f"[FATAL] Error type: {type(e).__name__}")
        print(f"[FATAL] Download directory: {os.path.abspath(DEFAULT_DOWNLOAD_DIR)}")
        print(f"[FATAL] Logs directory: {os.path.abspath(LOGS_PATH)}")
        try:
            logger.error(f"[FATAL] {e}", exc_info=True)
        except:
            pass  # If logging fails, continue

        print("\nPress Enter to close this terminal...")
        try:
            input()
        except EOFError:
            pass

        exit(1)
