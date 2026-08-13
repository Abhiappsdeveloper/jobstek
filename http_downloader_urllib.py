#!/usr/bin/env python3
"""
TekJobs Resume Downloader - urllib version (No External Dependencies)
Works on shared hosting without pip/requests
Uses only Python built-in libraries
"""

import sys
import io
import os
import logging
import time
import math
from datetime import datetime
from urllib.parse import urljoin, urlparse, urlencode
from urllib.request import Request, urlopen, build_opener, HTTPCookieProcessor
from urllib.error import URLError, HTTPError
import http.cookiejar
import re
import json

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
    LOGS_PATH = os.path.join(SCRIPT_DIR, 'storage', 'logs', 'resume_downloader')
    DEFAULT_DOWNLOAD_DIR = LARAVEL_STORAGE_PATH
    try:
        os.makedirs(LOGS_PATH, exist_ok=True)
        os.makedirs(LARAVEL_STORAGE_PATH, exist_ok=True)
        try:
            os.chmod(os.path.join(SCRIPT_DIR, 'storage'), 0o775)
            os.chmod(LARAVEL_STORAGE_PATH, 0o775)
            os.chmod(LOGS_PATH, 0o775)
        except:
            pass
    except Exception as e:
        print(f"[WARNING] Could not auto-create directories: {e}")
else:
    LOGS_PATH = os.path.dirname(__file__)
    DEFAULT_DOWNLOAD_DIR = os.path.join(SCRIPT_DIR, 'downloads')
    try:
        os.makedirs(DEFAULT_DOWNLOAD_DIR, exist_ok=True)
    except Exception as e:
        print(f"[WARNING] Could not create downloads directory: {e}")

# Logging Setup
LOG_FILE = os.path.join(LOGS_PATH, f"http_downloader_{datetime.now().strftime('%Y%m%d_%H%M%S')}.log")
DOWNLOADED_TRACKER_FILE = os.path.join(DEFAULT_DOWNLOAD_DIR, "downloaded_resumes.txt")
S3_URLS_TRACKER_FILE = os.path.join(DEFAULT_DOWNLOAD_DIR, "resume_s3_urls.txt")

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


class URLSession:
    """urllib-based session handler with cookie support"""
    def __init__(self):
        self.cookie_jar = http.cookiejar.CookieJar()
        self.opener = build_opener(HTTPCookieProcessor(self.cookie_jar))
        self.headers = {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language': 'en-US,en;q=0.5',
            'Connection': 'keep-alive',
        }

    def get(self, url, timeout=15):
        """GET request"""
        try:
            req = Request(url, headers=self.headers)
            response = self.opener.open(req, timeout=timeout)
            content = response.read().decode('utf-8', errors='ignore')
            return Response(200, content, dict(response.headers))
        except HTTPError as e:
            content = e.read().decode('utf-8', errors='ignore')
            return Response(e.code, content, dict(e.headers))
        except Exception as e:
            logger.error(f"[ERROR] GET request failed: {str(e)}")
            raise

    def post(self, url, data=None, timeout=15):
        """POST request"""
        try:
            post_data = urlencode(data).encode('utf-8') if data else None
            req = Request(url, data=post_data, headers=self.headers)
            response = self.opener.open(req, timeout=timeout)
            content = response.read().decode('utf-8', errors='ignore')
            return Response(200, content, dict(response.headers))
        except HTTPError as e:
            content = e.read().decode('utf-8', errors='ignore')
            return Response(e.code, content, dict(e.headers))
        except Exception as e:
            logger.error(f"[ERROR] POST request failed: {str(e)}")
            raise


class Response:
    """Response object"""
    def __init__(self, status_code, text, headers):
        self.status_code = status_code
        self.text = text
        self.headers = headers

    def raise_for_status(self):
        if self.status_code >= 400:
            raise HTTPError(None, self.status_code, f"HTTP {self.status_code}", None, None)


def load_downloaded_resumes():
    """Load list of already downloaded resume IDs"""
    downloaded_set = set()
    try:
        if os.path.exists(DOWNLOADED_TRACKER_FILE):
            with open(DOWNLOADED_TRACKER_FILE, 'r', encoding='utf-8') as f:
                for line in f:
                    line = line.strip()
                    if line and not line.startswith('#'):
                        downloaded_set.add(line)
            logger.info(f"[RESUME-SKIP] Loaded {len(downloaded_set)} previously downloaded IDs")
    except Exception as e:
        logger.warning(f"[RESUME-SKIP] Could not load downloaded resumes: {e}")
    return downloaded_set


def mark_resume_as_downloaded(resume_id):
    """Mark resume as successfully downloaded"""
    try:
        os.makedirs(os.path.dirname(DOWNLOADED_TRACKER_FILE), exist_ok=True)
        with open(DOWNLOADED_TRACKER_FILE, 'a', encoding='utf-8') as f:
            f.write(f"{resume_id}\n")
    except Exception as e:
        logger.warning(f"[RESUME-SKIP] Could not mark resume as downloaded: {e}")


def initialize_downloaded_tracker():
    """Initialize the downloaded resumes tracker file"""
    try:
        tracker_dir = os.path.dirname(DOWNLOADED_TRACKER_FILE)
        os.makedirs(tracker_dir, exist_ok=True)
        if not os.path.exists(DOWNLOADED_TRACKER_FILE):
            with open(DOWNLOADED_TRACKER_FILE, 'w', encoding='utf-8') as f:
                f.write("# Downloaded Resumes Tracker\n")
                f.write(f"# Auto-generated: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
    except Exception as e:
        logger.warning(f"[RESUME-SKIP] Could not initialize tracker: {e}")


def login(session, email, password, base_url='https://www.tekjobs.net'):
    """Authenticate with TekJobs"""
    try:
        logger.info("[LOGIN] Attempting login...")
        print("[LOGIN] Attempting login with email and password...")

        # Fetch login page first
        login_url = urljoin(base_url, '/login')
        response = session.get(login_url, timeout=10)
        logger.info(f"[LOGIN] Login page status: {response.status_code}")

        time.sleep(1)

        # Submit login credentials
        login_data = {'email': email, 'password': password}
        login_post_url = urljoin(base_url, '/api/login')
        response = session.post(login_post_url, data=login_data, timeout=10)

        if response.status_code in [200, 302]:
            print("[OK] Login successful")
            logger.info(f"[LOGIN] Login successful: {response.status_code}")
            return True
        else:
            print(f"[WARNING] Login returned status {response.status_code}")
            return True

    except Exception as e:
        logger.error(f"[ERROR] Login failed: {str(e)}")
        print(f"[ERROR] Login failed: {str(e)}")
        return False


def extract_resume_ids_from_html(html_content):
    """Extract resume IDs from HTML"""
    resume_ids = []
    try:
        pattern = r"onclick=['\"]downloadResume\('([^'\"]+)'\)"
        matches = re.findall(pattern, html_content)
        if matches:
            resume_ids = matches
            logger.info(f"[PARSE] Extracted {len(resume_ids)} resume IDs")
    except Exception as e:
        logger.warning(f"[PARSE] HTML parsing error: {type(e).__name__}")
    return resume_ids


def extract_s3_download_url_from_detail(session, resume_id, base_url='https://www.tekjobs.net'):
    """Extract S3 URL from resume detail page"""
    try:
        detail_url = urljoin(base_url, f'/employer/searchResume/resume/{resume_id}/')
        print(f"[DETAIL] Fetching detail page for {resume_id}...")
        response = session.get(detail_url, timeout=15)
        response.raise_for_status()

        html_content = response.text

        # Look for S3 URL patterns
        patterns = [
            r'const\s+resume_org_path\s*=\s*["\']([^"\']+)["\']',
            r'resume_org_path\s*:\s*["\']([^"\']+)["\']',
        ]

        for pattern in patterns:
            matches = re.findall(pattern, html_content)
            if matches:
                print(f"[OK] Found S3 URL")
                logger.info(f"[DETAIL] S3 URL extracted for {resume_id}")
                return matches[0]

        print(f"[WARN] Could not find S3 URL in detail page")
        return None

    except Exception as e:
        logger.error(f"[ERROR] Failed to extract S3 URL: {str(e)}")
        print(f"[ERROR] Detail page error: {type(e).__name__}")
        return None


def get_resume_list_page(session, page=1, base_url='https://www.tekjobs.net', country='usa'):
    """Fetch resume list page"""
    try:
        url = urljoin(base_url, f'/employer/searchResume/index/{page}/?country={country}')
        print(f"[FETCH] Requesting: {url}")
        response = session.get(url, timeout=15)
        response.raise_for_status()

        print(f"[OK] Resume list page fetched (status: {response.status_code})")
        logger.info(f"[FETCH] Resume list page {page} retrieved")

        resume_ids = extract_resume_ids_from_html(response.text)
        if resume_ids:
            print(f"[OK] Found {len(resume_ids)} resume(s) on page {page}")
            return resume_ids, True
        else:
            print(f"[WARN] No resume IDs found on page {page}")
            return [], False

    except Exception as e:
        logger.error(f"[ERROR] Failed to get resume list page {page}: {str(e)}")
        print(f"[ERROR] Failed to get resume list: {str(e)}")
        return [], False


def get_all_resume_ids(session, base_url='https://www.tekjobs.net', country='usa', max_pages=10):
    """Fetch resume IDs from all pages"""
    all_resume_ids = []
    page = 1

    print("\n[PAGINATION] Starting to fetch resumes from all pages...")

    while page <= max_pages:
        try:
            print(f"\n[PAGINATION] Fetching page {page}...")
            resume_ids, has_content = get_resume_list_page(session, page, base_url, country)

            if not has_content or not resume_ids:
                print(f"[PAGINATION] No more resumes found. Stopping at page {page}")
                break

            all_resume_ids.extend(resume_ids)
            print(f"[PAGINATION] Total resumes so far: {len(all_resume_ids)}")

            page += 1
            time.sleep(1)

        except Exception as e:
            logger.error(f"[PAGINATION] Error on page {page}: {str(e)}")
            break

    print(f"\n[PAGINATION] Complete - Total resumes: {len(all_resume_ids)}")
    logger.info(f"[PAGINATION] Total resumes found: {len(all_resume_ids)}")

    return all_resume_ids


def download_resume_by_id(session, resume_id, download_dir, base_url='https://www.tekjobs.net'):
    """Download a resume by ID"""
    try:
        os.makedirs(download_dir, exist_ok=True)
        logger.info(f"[DOWNLOAD] Processing resume: {resume_id}")
        print(f"[DOWNLOAD] Processing resume ID: {resume_id}")

        # Get S3 URL from detail page
        s3_url = extract_s3_download_url_from_detail(session, resume_id, base_url)

        if not s3_url:
            logger.error(f"[DOWNLOAD] No S3 URL found for {resume_id}")
            print(f"[ERROR] Could not find S3 URL for resume {resume_id}")
            return False

        # Download from S3 URL
        print(f"[STEP 2] Downloading from S3...")
        response = session.get(s3_url, timeout=20)

        if response.status_code != 200:
            logger.error(f"[DOWNLOAD] S3 returned {response.status_code}")
            print(f"[ERROR] S3 download returned status {response.status_code}")
            return False

        # Determine filename
        content_disposition = response.headers.get('content-disposition', '')
        filename = None

        if 'filename=' in content_disposition:
            filename = content_disposition.split('filename=')[1].strip('"\'')

        if not filename:
            s3_path = s3_url.split('?')[0]
            filename = os.path.basename(s3_path)

        if not filename or filename.startswith('/'):
            filename = f"resume_{resume_id}.pdf"

        file_path = os.path.join(download_dir, filename)

        # Write file
        print(f"[STEP 3] Writing file: {filename}")
        with open(file_path, 'wb') as f:
            content = response.text.encode('utf-8') if isinstance(response.text, str) else response.text
            f.write(content)

        file_size = os.path.getsize(file_path)
        print(f"[OK] Downloaded: {filename} ({file_size} bytes)")
        logger.info(f"[DOWNLOAD] Success: {filename} ({file_size} bytes)")

        mark_resume_as_downloaded(resume_id)
        return True

    except Exception as e:
        logger.error(f"[ERROR] Download failed: {str(e)}")
        print(f"[ERROR] Download failed: {str(e)}")
        return False


def main():
    """Main execution function"""
    logger.info("\n" + "=" * 70)
    logger.info("[STARTUP] TekJobs Resume Downloader - urllib version (No External Dependencies)")
    if USE_LARAVEL_STORAGE:
        logger.info("[STARTUP] Environment: Hostinger Shared Hosting (Laravel Storage)")
    logger.info("=" * 70)

    print("\n" + "=" * 70)
    print("TekJobs Resume Downloader - urllib version")
    print("=" * 70)

    initialize_downloaded_tracker()
    downloaded_resumes = load_downloaded_resumes()

    email = os.getenv('TEKJOBS_EMAIL', 'abhijeetkumar9006185@gmail.com')
    password = os.getenv('TEKJOBS_PASSWORD', 'Login@123')

    session = URLSession()

    print("\n[STEP 1] Authenticating with TekJobs...")
    if not login(session, email, password):
        logger.warning("[LOGIN] Login may have failed, continuing...")

    time.sleep(2)

    print("\n[STEP 2] Fetching resume list...")
    resume_ids = get_all_resume_ids(session, max_pages=5)

    successful = 0
    failed = 0
    skipped = 0

    if resume_ids:
        print(f"\n[STEP 3] Downloading {len(resume_ids)} resume(s)...")

        for idx, resume_id in enumerate(resume_ids, 1):
            try:
                if resume_id in downloaded_resumes:
                    print(f"\n[SKIP] Resume {resume_id} already downloaded")
                    logger.info(f"[RESUME-SKIP] Skipped {resume_id}")
                    skipped += 1
                    continue

                print(f"\n[{idx}/{len(resume_ids)}] Downloading resume {idx}/{len(resume_ids)}...")

                if download_resume_by_id(session, resume_id, DEFAULT_DOWNLOAD_DIR, base_url='https://www.tekjobs.net'):
                    successful += 1
                else:
                    failed += 1

                time.sleep(1)

            except Exception as e:
                logger.error(f"[ERROR] Resume {resume_id}: {str(e)}")
                print(f"[ERROR] Resume {resume_id}: {str(e)}")
                failed += 1

        print("\n" + "=" * 70)
        print("[SUMMARY] Download Complete")
        print("=" * 70)
        print(f"Skipped: {skipped}, Successful: {successful}, Failed: {failed}")
        print(f"Storage: {DEFAULT_DOWNLOAD_DIR}")
        print("=" * 70)

        logger.info(f"[COMPLETE] Downloads - Skipped: {skipped}, Successful: {successful}, Failed: {failed}")
        return successful > 0
    else:
        print("\n[INFO] No resumes to download")
        logger.info("[INFO] No resumes found")
        return False


if __name__ == "__main__":
    try:
        success = main()
        exit(0 if success else 1)
    except Exception as e:
        logger.error(f"[FATAL] {e}", exc_info=True)
        print(f"\n[FATAL] Fatal error: {e}")
        exit(1)
