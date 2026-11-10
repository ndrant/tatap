#!/usr/bin/env python3
"""Packaging & Deployment CLI tool for Tatap Web PHP Edition.

Bundles frontend web assets and PHP backend into a self-contained ZIP package
ready for shared hosting (cPanel, LiteSpeed, Apache) with optional FTP/FTPS deployment.
"""

import os
import sys
import shutil
import zipfile
import argparse
import ftplib
from pathlib import Path

ROOT_DIR = Path(__file__).resolve().parent.parent


def get_version() -> str:
    version_file = ROOT_DIR / "VERSION"
    if version_file.is_file():
        return version_file.read_text().strip()
    return "2.12.7"


def build_php_package(output_zip: str = "dist/tatap-web-php.zip", build_dir: str = "dist/tatap-web-php") -> str:
    """Bundle frontend and php backend into clean production directory and zip."""
    root = ROOT_DIR
    out_zip = Path(output_zip).resolve()
    target_dir = Path(build_dir).resolve()

    print(f"[*] Packaging Tatap Web PHP (v{get_version()})...")

    # Clean target staging directory
    if target_dir.exists():
        shutil.rmtree(target_dir)
    target_dir.mkdir(parents=True, exist_ok=True)

    # 1. Copy Frontend Static Assets directly to root
    frontend_dir = root / "frontend"
    print(f"[*] Copying frontend assets from {frontend_dir}...")
    for item in frontend_dir.iterdir():
        if item.name.startswith("."):
            continue
        dest = target_dir / item.name
        if item.is_dir():
            shutil.copytree(item, dest)
        else:
            shutil.copy2(item, dest)

    # 2. Copy PHP Backend into api/
    api_dest = target_dir / "api"
    php_src = root / "php"
    print(f"[*] Copying PHP backend from {php_src} into api/...")
    shutil.copytree(php_src, api_dest)

    # 3. Create data directory with protected .htaccess
    data_dest = target_dir / "data"
    data_dest.mkdir(parents=True, exist_ok=True)
    cache_dest = data_dest / "cache"
    cache_dest.mkdir(parents=True, exist_ok=True)

    data_htaccess = root / "data" / ".htaccess"
    if data_htaccess.is_file():
        shutil.copy2(data_htaccess, data_dest / ".htaccess")
    else:
        (data_dest / ".htaccess").write_text("<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\nOrder deny,allow\nDeny from all\n")

    # 4. Copy Root .htaccess
    htaccess_example = root / ".htaccess.example"
    if htaccess_example.is_file():
        shutil.copy2(htaccess_example, target_dir / ".htaccess")

    # 5. Build ZIP Archive
    out_zip.parent.mkdir(parents=True, exist_ok=True)
    if out_zip.exists():
        out_zip.unlink()

    print(f"[*] Compressing bundle into {out_zip}...")
    with zipfile.ZipFile(out_zip, "w", zipfile.ZIP_DEFLATED) as zf:
        for root_path, dirs, files in os.walk(target_dir):
            for file in files:
                full_path = Path(root_path) / file
                rel_path = full_path.relative_to(target_dir)
                zf.write(full_path, arcname=str(rel_path))

    zip_size_mb = out_zip.stat().st_size / (1024 * 1024)
    print(f"[✓] Successfully built: {out_zip} ({zip_size_mb:.2f} MB)")
    return str(out_zip)


def deploy_ftp(
    source_dir_or_zip: str,
    host: str,
    user: str,
    password: str,
    remote_dir: str = "/public_html",
    port: int = 21,
    use_ssl: bool = False,
    upload_zip_only: bool = False,
) -> bool:
    """Deploy files or package ZIP to shared hosting via FTP/FTPS."""
    print(f"[*] Connecting to FTP server {host}:{port} (SSL={use_ssl})...")
    try:
        if use_ssl:
            ftp = ftplib.FTP_TLS()
            ftp.connect(host, port, timeout=30)
            ftp.login(user, password)
            ftp.prot_p()
        else:
            ftp = ftplib.FTP()
            ftp.connect(host, port, timeout=30)
            ftp.login(user, password)

        print(f"[✓] Logged in as '{user}'.")

        # Navigate or create remote directory
        parts = [p for p in remote_dir.strip("/").split("/") if p]
        for part in parts:
            try:
                ftp.cwd(part)
            except ftplib.error_perm:
                print(f"[*] Creating remote directory: {part}")
                ftp.mkd(part)
                ftp.cwd(part)

        current_remote = ftp.pwd()
        print(f"[*] Current remote working directory: {current_remote}")

        src_path = Path(source_dir_or_zip).resolve()

        if upload_zip_only or src_path.is_file():
            # Upload single ZIP file
            zip_file = src_path if src_path.is_file() else Path(f"{source_dir_or_zip}.zip")
            print(f"[*] Uploading ZIP package {zip_file.name} to {current_remote}...")
            with open(zip_file, "rb") as f:
                ftp.storbinary(f"STOR {zip_file.name}", f)
            print(f"[✓] Upload complete: {zip_file.name}")
        else:
            # Upload directory recursively
            print(f"[*] Uploading files recursively from {src_path}...")

            def _upload_dir(local_dir: Path):
                for item in local_dir.iterdir():
                    if item.is_dir():
                        dir_name = item.name
                        try:
                            ftp.cwd(dir_name)
                        except ftplib.error_perm:
                            try:
                                ftp.mkd(dir_name)
                            except Exception:
                                pass
                            ftp.cwd(dir_name)
                        _upload_dir(item)
                        ftp.cwd("..")
                    else:
                        with open(item, "rb") as f:
                            ftp.storbinary(f"STOR {item.name}", f)
                        print(f"  -> Uploaded: {item.name}")

            _upload_dir(src_path)
            print(f"[✓] All files uploaded to {current_remote}")

        ftp.quit()
        return True

    except Exception as e:
        print(f"[!] FTP deployment failed: {e}", file=sys.stderr)
        return False


def main():
    parser = argparse.ArgumentParser(
        description="Tatap Web PHP Packaging & Deployment Tool"
    )
    parser.add_argument(
        "--output",
        "-o",
        default="dist/tatap-web-php.zip",
        help="Output ZIP destination (default: dist/tatap-web-php.zip)",
    )
    parser.add_argument(
        "--staging-dir",
        default="dist/tatap-web-php",
        help="Staging directory for bundle (default: dist/tatap-web-php)",
    )
    parser.add_argument(
        "--deploy-ftp",
        action="store_true",
        help="Deploy to shared hosting via FTP/FTPS after build",
    )
    parser.add_argument("--ftp-host", help="FTP server hostname (e.g. ftp.example.com)")
    parser.add_argument("--ftp-port", type=int, default=21, help="FTP port (default: 21)")
    parser.add_argument("--ftp-user", help="FTP username")
    parser.add_argument("--ftp-pass", help="FTP password")
    parser.add_argument(
        "--ftp-dir",
        default="/public_html",
        help="Target remote directory (default: /public_html)",
    )
    parser.add_argument(
        "--ftp-ssl", action="store_true", help="Use explicit FTPS (TLS)"
    )
    parser.add_argument(
        "--upload-zip-only",
        action="store_true",
        help="Upload the ZIP file only instead of extracting files on FTP",
    )

    args = parser.parse_args()

    zip_path = build_php_package(args.output, args.staging_dir)

    if args.deploy_ftp:
        if not args.ftp_host or not args.ftp_user or not args.ftp_pass:
            print("[!] Error: --deploy-ftp requires --ftp-host, --ftp-user, and --ftp-pass.", file=sys.stderr)
            sys.exit(1)

        success = deploy_ftp(
            source_dir_or_zip=args.staging_dir,
            host=args.ftp_host,
            user=args.ftp_user,
            password=args.ftp_pass,
            remote_dir=args.ftp_dir,
            port=args.ftp_port,
            use_ssl=args.ftp_ssl,
            upload_zip_only=args.upload_zip_only,
        )
        if not success:
            sys.exit(1)


if __name__ == "__main__":
    main()
