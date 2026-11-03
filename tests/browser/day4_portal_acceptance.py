"""Authenticated local-only native WordPress portal acceptance; never use production.

Requires a disposable local WordPress fixture with DigiForge active and a page
containing [digiforge_admin_portal]. Browser egress outside the exact loopback
origin is denied. All source/artwork/approval data below is synthetic test data.
"""
import base64
import json
import os
from pathlib import Path
import struct
from urllib.parse import urlparse, parse_qs
import zlib

from playwright.sync_api import sync_playwright


BASE = os.environ["DIGIFORGE_BROWSER_BASE_URL"].rstrip("/")
PARSED = urlparse(BASE)
if PARSED.scheme != "http" or PARSED.hostname not in ("127.0.0.1", "localhost"):
    raise SystemExit("Only a disposable loopback fixture is permitted")
PAGE = os.environ["DIGIFORGE_BROWSER_PORTAL_URL"]
if urlparse(PAGE).netloc != PARSED.netloc:
    raise SystemExit("Portal origin mismatch")
FIXTURES = Path(__file__).resolve().parents[1] / "fixtures"
OUTPUT = Path(os.environ.get("DIGIFORGE_BROWSER_OUTPUT", "/tmp/digiforge-day4-browser"))
OUTPUT.mkdir(parents=True, exist_ok=True)


def png_mug():
    def chunk(kind, data):
        return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data))
    return (b"\x89PNG\r\n\x1a\n"
            + chunk(b"IHDR", struct.pack(">IIBBBBB", 2475, 1155, 8, 2, 0, 0, 0))
            + chunk(b"pHYs", struct.pack(">IIB", 11811, 11811, 1))
            + chunk(b"IDAT", zlib.compress((b"\0" + b"\xff" * (2475 * 3)) * 1155))
            + chunk(b"IEND", b""))


def main():
    blocked = []
    failures = []
    receipt = {"classification": "SYNTHETIC_LOCAL_AUTHENTICATED_ACCEPTANCE",
               "production_acceptance": False, "fixtures": []}
    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path="/usr/bin/chromium", headless=True,
                                     args=["--no-sandbox"])
        context = browser.new_context(viewport={"width": 1280, "height": 900}, accept_downloads=True)

        def route(request_route):
            url = request_route.request.url
            if urlparse(url).netloc == PARSED.netloc:
                request_route.continue_()
            else:
                blocked.append(url)
                request_route.abort()
        context.route("**/*", route)
        page = context.new_page()
        page.on("pageerror", lambda error: failures.append(str(error)))
        page.goto(BASE + "/wp-login.php", wait_until="domcontentloaded")
        page.locator("#user_login").fill(os.environ["DIGIFORGE_BROWSER_USER"])
        page.locator("#user_pass").fill(os.environ["DIGIFORGE_BROWSER_PASSWORD"])
        with page.expect_navigation(wait_until="domcontentloaded"):
            page.locator("#wp-submit").click()
        assert "login_error" not in page.content(), "Local fixture sign-in failed"

        def form(operation):
            return page.locator(f'form:has(input[name="operation"][value="{operation}"])').first

        def submit(operation, fields, checks=(), selection=None, button="button"):
            f = form(operation)
            for key, value in fields.items():
                f.locator(f'[name="{key}"]').fill(value)
            for key in checks:
                f.locator(f'[name="{key}"]').check()
            if selection:
                for key, value in selection.items():
                    f.locator(f'[name="{key}"]').select_option(value)
            with page.expect_navigation(wait_until="domcontentloaded"):
                f.locator(button).click()
            assert parse_qs(urlparse(page.url).query).get("page_id") == parse_qs(urlparse(PAGE).query).get("page_id"), "Portal return page changed"
            assert parse_qs(urlparse(page.url).query).get("df_error") != ["1"], page.locator("body").inner_text()[-4000:]

        for family, fixture_id in (("mug", "fl-03"), ("poster", "fl-04")):
            page.goto(PAGE, wait_until="domcontentloaded")
            data = json.loads((FIXTURES / "research/commercial-research.json").read_text())
            data["research"]["product_family"] = family
            data["research"]["title"] += " — synthetic browser " + family
            submit("research_import", {"research_json": json.dumps(data["research"])})
            assert "RESEARCH_READY" in page.locator("body").inner_text()
            submit("concepts", {"response_json": json.dumps(data["response"])}, selection={"method": "manual"})
            assert page.locator("article.df-candidate").count() == 3
            submit("select", {})
            submit("design_approve_concept", {"note": "Synthetic local source review only"},
                   checks=("sources_reviewed", "buyer_intent_reviewed", "gifting_occasion_reviewed"))
            submit("design_prepare", {"layout_notes": "Original shapes outside native reservation",
                   "typography": "Licensed native text hierarchy", "font_license_reference": "Synthetic review reference",
                   "palette": "#123456,#ffffff", "asset_requirements": "Original geometry, separate native names",
                   "native_regions_json": json.dumps([{"name": "names", "kind": "text", "x": 500, "y": 400, "width": 1400, "height": 300}])},
                   selection={"profile": "fl03_mug_11oz" if family == "mug" else "fl04_poster_8x10"})
            rasters = [] if family == "poster" else [{"mime_type": "image/png", "base64": base64.b64encode(png_mug()).decode()}]
            submit("design_import", {"svg": (FIXTURES / f"design-factory/{family}-original.svg").read_text(),
                   "rasters_json": json.dumps(rasters), "rights_note": "Synthetic original fixture; no real commercial approval",
                   "source_note": "Local browser test only"})
            svg_preview = page.locator('img[src^="data:image/svg+xml;base64,"]')
            assert svg_preview.count() == 1 and svg_preview.evaluate("img => img.naturalWidth > 0")
            if rasters:
                assert page.locator('img[src^="data:image/png;base64,"]').evaluate("img => img.naturalWidth") == 2475
            criteria = ("visual_distinctiveness", "buyer_intent_and_gifting", "typography_and_composition",
                        "personalization_readability", "product_family_adaptation", "commercial_originality",
                        "print_geometry_compatibility", "originality_licensing", "mockup_reviewed", "confirm")
            submit("design_review", {"note": "Synthetic local criteria decision only"}, checks=criteria, selection={"decision": "APPROVE"})
            evidence = json.loads((FIXTURES / f"product-packages/{fixture_id}.json").read_text())
            submit("package_prepare", {"evidence_json": json.dumps(evidence)}, button='button[name="evidence_mode"][value="import"]')
            assert "PENDING_PACKAGE_REVIEW" in page.locator("body").inner_text()
            assert "Conditional economics" in page.locator("body").inner_text()
            submit("package_decide", {"note": "Synthetic held package decision, not commercial approval"}, checks=("confirm",), selection={"decision": "APPROVE"})
            f = form("package_export")
            hidden = dict(f.locator('input[type="hidden"]').evaluate_all("inputs => inputs.map(i => [i.name, i.value])"))
            bad = dict(hidden, confirm="1", _wpnonce="invalid-local-test-nonce")
            response = context.request.post(BASE + "/wp-admin/admin-post.php", form=bad)
            assert response.status == 403, "Invalid nonce unexpectedly accepted"
            f.locator('[name="confirm"]').check()
            with page.expect_download() as pending:
                f.locator("button").click()
            download = pending.value
            path = OUTPUT / f"{fixture_id}-SYNTHETIC-held-export.json"
            download.save_as(path)
            exported = json.loads(path.read_text())
            assert exported["release_hold"] is True and exported["external_execution_authorized"] is False
            assert exported["commercial_approved"] is False and len(path.read_bytes()) <= 65536
            assert exported["package"]["fixture_id"] == fixture_id.upper()
            assert len(exported["package"]["listing"]["tags"]) == 13
            assert download.suggested_filename.startswith("digiforge-held-package-")
            header_response = context.request.post(BASE + "/wp-admin/admin-post.php", form=dict(hidden, confirm="1"))
            headers = header_response.headers
            assert "application/json" in headers.get("content-type", "")
            assert "attachment;" in headers.get("content-disposition", "")
            assert "no-cache" in headers.get("cache-control", "")
            assert headers.get("x-content-type-options") == "nosniff"
            other = browser.new_context()
            anonymous = other.request.post(BASE + "/wp-admin/admin-post.php", form=dict(hidden, confirm="1"))
            assert anonymous.status in (400, 403) and '"package_hash"' not in anonymous.text()
            other.close()
            for width in (390, 768, 1280):
                page.set_viewport_size({"width": width, "height": 900})
                layout = page.locator('#df-commercial-research').evaluate("e => ({content: e.scrollWidth, visible: e.clientWidth})")
                assert layout["content"] <= layout["visible"] + 1, f"Workflow overflow at {width}px: {layout}"
                page.screenshot(path=str(OUTPUT / f"{fixture_id}-{width}.png"), full_page=True)
            receipt["fixtures"].append({"fixture": fixture_id.upper(), "package_hash": exported["package_hash"],
                                        "held_export_bytes": path.stat().st_size,
                                        "download_headers_verified": True, "bad_nonce_denied": True,
                                        "anonymous_denied": True, "viewports": [390, 768, 1280]})
        receipt["blocked_external_requests"] = blocked
        receipt["browser_errors"] = failures
        assert not blocked, "Unexpected browser egress attempt: " + repr(blocked)
        assert not failures, "Browser JavaScript errors: " + repr(failures)
        (OUTPUT / "receipt.json").write_text(json.dumps(receipt, indent=2) + "\n")
        print(json.dumps(receipt, indent=2))
        browser.close()


if __name__ == "__main__":
    main()
