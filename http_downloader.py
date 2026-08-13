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
from datetime import datetime
from urllib.parse import urljoin, urlparse, urlencode
from urllib.request import Request, urlopen
from urllib.error import URLError, HTTPError
import http.cookiejar
import re

# Try to use requests if available, otherwise use urllib
try:
    import requests
    HAS_REQUESTS = True
except ImportError:
    HAS_REQUESTS = False
    # We'll use urllib instead

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
    except Exception as e:
        print(f"[WARNING] Could not create downloads directory: {e}")

# Logging Setup
LOG_FILE = os.path.join(LOGS_PATH, f"http_downloader_{datetime.now().strftime('%Y%m%d_%H%M%S')}.log")
RESUME_LINKS_FILE = os.path.join(LOGS_PATH, f"resume_links_{datetime.now().strftime('%Y%m%d_%H%M%S')}.txt")
ERROR_TRACKING_FILE = os.path.join(LOGS_PATH, f"error_tracking_{datetime.now().strftime('%Y%m%d_%H%M%S')}.txt")
DOWNLOADED_TRACKER_FILE = os.path.join(DEFAULT_DOWNLOAD_DIR, "downloaded_resumes.txt")  # Persistent tracking file
S3_URLS_TRACKER_FILE = os.path.join(DEFAULT_DOWNLOAD_DIR, "resume_s3_urls.txt")  # Store S3 URLs for quick recovery

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


# Initialize tracking files
def initialize_tracking_files():
    """Create tracking files for resume links and errors"""
    try:
        # Ensure logs directory exists
        os.makedirs(LOGS_PATH, exist_ok=True)

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
        with open(S3_URLS_TRACKER_FILE, 'a', encoding='utf-8') as f:
            f.write(f"{resume_id}|{filename}|{s3_url}\n")
    except Exception as e:
        logger.warning(f"[S3-RECOVERY] Could not store S3 URL: {e}")


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
    """Create a requests session with proper headers"""
    session = requests.Session()

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

        response = session.get(login_url, timeout=10)
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

        response = session.post(login_post_url, data=login_data, timeout=10, allow_redirects=True)

        if response.status_code in [200, 302]:
            print(f"[OK] Login successful (status: {response.status_code})")
            logger.info(f"[LOGIN] Login POST successful: {response.status_code}")
            print(f"[OK] Session established with {len(session.cookies)} cookies")
            return True
        else:
            print(f"[WARNING] Login returned status {response.status_code}")
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

        response = session.get(detail_url, timeout=15)
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
    """Fetch resume IDs from all pages by incrementing page number"""
    all_resume_ids = []
    page = 1
    total_pages_checked = 0
    calculated_max_pages = max_pages or 10000  # Large default if not calculated

    print("\n[PAGINATION] Starting to fetch resumes from all pages...")
    print(f"[PAGINATION] URL format: /employer/searchResume/index/PAGE/?country={country}")

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
        store_s3_url(resume_id, filename, download_url)
        logger.info(f"[S3-RECOVERY] Stored S3 URL for {resume_id}")

        return True

    except Exception as e:
        logger.error(f"[ERROR] Download failed for {resume_id}: {str(e)}")
        print(f"[ERROR] Download failed: {type(e).__name__}: {str(e)}")
        log_error("DOWNLOAD_EXCEPTION", resume_id, f"{type(e).__name__}: {str(e)}")
        return False


def main():
    """Main execution function"""
    logger.info("\n" + "=" * 70)
    logger.info("[STARTUP] TekJobs Resume Downloader - Requests-based (No Selenium)")
    if USE_LARAVEL_STORAGE:
        logger.info("[STARTUP] Environment: Hostinger Shared Hosting (Laravel Storage)")
    else:
        logger.info("[STARTUP] Environment: Local Development")
    logger.info("=" * 70)

    print("\n" + "=" * 70)
    print("TekJobs Resume Downloader - HTTP Direct Download")
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

    # Get resume list from ALL pages with pagination
    print("\n[STEP 2] Fetching resume list from all pages...")
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
        logger.error(f"[FATAL] {e}", exc_info=True)

        print("\nPress Enter to close this terminal...")
        try:
            input()
        except EOFError:
            pass

        exit(1)
