#!/usr/bin/env python3
"""
design_fetcher.py
==================
Fetches the full front-end design of a web page (HTML, CSS, JS, images,
fonts) and produces a design-analysis report (colors, fonts, layout,
components) so you can recreate a similar-looking page.

Usage:
    python design_fetcher.py https://www.tekjobs.net/login
    python design_fetcher.py https://www.tekjobs.net/login --out design_capture/tekjobs_login

Notes:
- Only use this on sites you own or are authorized to inspect/clone.
- This downloads publicly served assets (HTML/CSS/JS/images/fonts) the
  same way a browser does; it does not bypass auth or any protection.
"""

import argparse
import json
import os
import re
import sys
from collections import Counter
from urllib.parse import urljoin, urlparse

import requests
from bs4 import BeautifulSoup

try:
    import cssutils
    cssutils.log.setLevel("FATAL")  # silence noisy CSS parsing warnings
    HAS_CSSUTILS = True
except ImportError:
    HAS_CSSUTILS = False

HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
        "(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36"
    )
}

COLOR_RE = re.compile(
    r"#[0-9a-fA-F]{3,8}\b|rgba?\([^)]+\)|hsla?\([^)]+\)"
)
FONT_FAMILY_RE = re.compile(r"font-family\s*:\s*([^;}{]+)", re.IGNORECASE)


def safe_filename(url: str) -> str:
    parsed = urlparse(url)
    name = os.path.basename(parsed.path) or "index"
    if not os.path.splitext(name)[1]:
        name += ".file"
    return name


def download(url: str, out_dir: str, session: requests.Session) -> str | None:
    """Download a single asset, return the local relative path or None."""
    try:
        resp = session.get(url, headers=HEADERS, timeout=20)
        resp.raise_for_status()
    except requests.RequestException as e:
        print(f"  [skip] {url} -> {e}")
        return None

    fname = safe_filename(url)
    # avoid collisions
    base, ext = os.path.splitext(fname)
    candidate = fname
    i = 1
    while os.path.exists(os.path.join(out_dir, candidate)):
        candidate = f"{base}_{i}{ext}"
        i += 1
    fname = candidate

    path = os.path.join(out_dir, fname)
    with open(path, "wb") as f:
        f.write(resp.content)
    return fname


def analyze_css_text(css_text: str, colors: Counter, fonts: Counter):
    for m in COLOR_RE.finditer(css_text):
        colors[m.group(0).lower()] += 1
    for m in FONT_FAMILY_RE.finditer(css_text):
        fam = m.group(1).strip().strip(";")
        fonts[fam] += 1


def analyze_layout(soup: BeautifulSoup) -> dict:
    def count(tag):
        return len(soup.find_all(tag))

    layout = {
        "headings": {f"h{i}": count(f"h{i}") for i in range(1, 7)},
        "forms": count("form"),
        "inputs": count("input"),
        "buttons": count("button") + len(soup.select('input[type="submit"]')),
        "images": count("img"),
        "links": count("a"),
        "nav_elements": count("nav"),
        "sections": count("section") + count("div"),
    }

    form_fields = []
    for form in soup.find_all("form"):
        fields = []
        for inp in form.find_all(["input", "select", "textarea"]):
            fields.append({
                "tag": inp.name,
                "type": inp.get("type", ""),
                "name": inp.get("name", ""),
                "placeholder": inp.get("placeholder", ""),
                "id": inp.get("id", ""),
            })
        form_fields.append({
            "action": form.get("action", ""),
            "method": form.get("method", "get"),
            "fields": fields,
        })
    layout["form_details"] = form_fields
    return layout


def fetch_design(url: str, out_dir: str):
    os.makedirs(out_dir, exist_ok=True)
    assets_dir = os.path.join(out_dir, "assets")
    css_dir = os.path.join(assets_dir, "css")
    js_dir = os.path.join(assets_dir, "js")
    img_dir = os.path.join(assets_dir, "img")
    font_dir = os.path.join(assets_dir, "fonts")
    for d in (css_dir, js_dir, img_dir, font_dir):
        os.makedirs(d, exist_ok=True)

    session = requests.Session()
    print(f"[*] Fetching {url}")
    resp = session.get(url, headers=HEADERS, timeout=20)
    resp.raise_for_status()
    html = resp.text

    with open(os.path.join(out_dir, "page.html"), "w", encoding="utf-8") as f:
        f.write(html)

    soup = BeautifulSoup(html, "html.parser")
    colors, fonts = Counter(), Counter()

    # inline <style> blocks
    for style_tag in soup.find_all("style"):
        analyze_css_text(style_tag.get_text(), colors, fonts)

    # inline style="" attributes
    for tag in soup.find_all(style=True):
        analyze_css_text(tag["style"], colors, fonts)

    # external CSS
    print("[*] Downloading CSS files...")
    for link in soup.find_all("link", rel=lambda v: v and "stylesheet" in v):
        href = link.get("href")
        if not href:
            continue
        css_url = urljoin(url, href)
        fname = download(css_url, css_dir, session)
        if fname:
            try:
                css_text = open(os.path.join(css_dir, fname), encoding="utf-8", errors="ignore").read()
                analyze_css_text(css_text, colors, fonts)
                # find font files referenced inside CSS (@font-face url(...))
                for fm in re.finditer(r"url\((['\"]?)([^'\")]+)\1\)", css_text):
                    font_url = urljoin(css_url, fm.group(2))
                    if any(font_url.lower().endswith(ext) for ext in (".woff", ".woff2", ".ttf", ".otf", ".eot")):
                        download(font_url, font_dir, session)
            except OSError:
                pass

    # external JS
    print("[*] Downloading JS files...")
    for script in soup.find_all("script", src=True):
        js_url = urljoin(url, script["src"])
        download(js_url, js_dir, session)

    # images
    print("[*] Downloading images...")
    for img in soup.find_all("img", src=True):
        img_url = urljoin(url, img["src"])
        download(img_url, img_dir, session)
    # favicon
    for link in soup.find_all("link", rel=lambda v: v and "icon" in v):
        href = link.get("href")
        if href:
            download(urljoin(url, href), img_dir, session)

    layout = analyze_layout(soup)

    report = {
        "source_url": url,
        "title": soup.title.get_text(strip=True) if soup.title else "",
        "meta_description": (soup.find("meta", attrs={"name": "description"}) or {}).get("content", "")
        if soup.find("meta", attrs={"name": "description"}) else "",
        "top_colors": colors.most_common(15),
        "font_families": [f for f, _ in fonts.most_common(10)],
        "layout": layout,
        "asset_counts": {
            "css_files": len(os.listdir(css_dir)),
            "js_files": len(os.listdir(js_dir)),
            "images": len(os.listdir(img_dir)),
            "fonts": len(os.listdir(font_dir)),
        },
    }

    report_path = os.path.join(out_dir, "design_report.json")
    with open(report_path, "w", encoding="utf-8") as f:
        json.dump(report, f, indent=2)

    md_path = os.path.join(out_dir, "design_report.md")
    with open(md_path, "w", encoding="utf-8") as f:
        f.write(f"# Design Analysis: {report['title'] or url}\n\n")
        f.write(f"Source: {url}\n\n")
        f.write("## Top Colors\n\n")
        for c, n in report["top_colors"]:
            f.write(f"- `{c}` (used {n}x)\n")
        f.write("\n## Font Families\n\n")
        for fam in report["font_families"]:
            f.write(f"- {fam}\n")
        f.write("\n## Layout Summary\n\n")
        f.write(f"- Headings: {layout['headings']}\n")
        f.write(f"- Forms: {layout['forms']}\n")
        f.write(f"- Inputs: {layout['inputs']}\n")
        f.write(f"- Buttons: {layout['buttons']}\n")
        f.write(f"- Images: {layout['images']}\n")
        f.write(f"- Links: {layout['links']}\n")
        if layout["form_details"]:
            f.write("\n## Form Field Details\n\n")
            for i, form in enumerate(layout["form_details"], 1):
                f.write(f"### Form {i} (method={form['method']}, action={form['action']})\n\n")
                for field in form["fields"]:
                    f.write(f"- {field['tag']} type={field['type']} name={field['name']} "
                            f"placeholder=\"{field['placeholder']}\"\n")
        f.write(f"\n## Assets Downloaded\n\n")
        for k, v in report["asset_counts"].items():
            f.write(f"- {k}: {v}\n")

    print(f"\n[+] Done. Output saved to: {out_dir}")
    print(f"    - Full HTML:     page.html")
    print(f"    - CSS/JS/Images: assets/")
    print(f"    - Report (JSON): design_report.json")
    print(f"    - Report (MD):   design_report.md")
    return report


def main():
    parser = argparse.ArgumentParser(description="Fetch and analyze a web page's design.")
    parser.add_argument("url", help="Page URL to analyze, e.g. https://www.tekjobs.net/login")
    parser.add_argument("--out", default=None, help="Output directory (default: design_capture/<host>_<path>)")
    args = parser.parse_args()

    if args.out:
        out_dir = args.out
    else:
        parsed = urlparse(args.url)
        slug = re.sub(r"[^a-zA-Z0-9]+", "_", parsed.netloc + parsed.path).strip("_")
        out_dir = os.path.join("design_capture", slug)

    try:
        fetch_design(args.url, out_dir)
    except requests.RequestException as e:
        print(f"[!] Failed to fetch {args.url}: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
