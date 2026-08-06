#!/usr/bin/env python3
"""
as3-huagao3d-glb-validate.py
GLB file validation for huagao3d project

Validates GLB files against expected criteria:
- glTF binary header magic and version
- Declared file length matches actual size
- SHA-256 checksum (optional, from assets.json or CLI arg)

Usage:
  python3 as3-huagao3d-glb-validate.py <glb-file>
  python3 as3-huagao3d-glb-validate.py <glb-file> --sha256 <expected-hash>
  python3 as3-huagao3d-glb-validate.py <glb-file> --assets-json <path-to-assets.json>

Exit codes:
  0 = all checks passed
  1 = validation failed
  2 = file not found or invalid arguments
"""

import sys
import os
import struct
import hashlib
import json
from pathlib import Path


def validate_glb_header(file_path):
    """
    Validate GLB file header and return metadata.
    
    Returns:
        dict with keys: magic, version, declared_length, actual_size, valid
    """
    actual_size = os.path.getsize(file_path)
    
    with open(file_path, 'rb') as f:
        header_bytes = f.read(12)
    
    if len(header_bytes) < 12:
        return {
            'magic': None,
            'version': None,
            'declared_length': None,
            'actual_size': actual_size,
            'valid': False,
            'error': 'File too small to contain GLB header'
        }
    
    magic, version, declared_length = struct.unpack('<4sII', header_bytes)
    
    valid = (
        magic == b'glTF' and
        version == 2 and
        declared_length == actual_size
    )
    
    return {
        'magic': magic.decode('ascii', errors='replace'),
        'version': version,
        'declared_length': declared_length,
        'actual_size': actual_size,
        'valid': valid,
        'error': None if valid else 'Header validation failed'
    }


def compute_sha256(file_path):
    """Compute SHA-256 hash of file."""
    hasher = hashlib.sha256()
    with open(file_path, 'rb') as f:
        while chunk := f.read(1048576):  # 1MB chunks
            hasher.update(chunk)
    return hasher.hexdigest()


def find_expected_hash_in_assets(assets_json_path, glb_filename):
    """
    Find expected SHA-256 from assets.json by matching filename.
    
    Returns:
        (expected_hash, file_key) or (None, None) if not found
    """
    try:
        with open(assets_json_path, 'r', encoding='utf-8') as f:
            manifest = json.load(f)
        
        if not isinstance(manifest, dict) or 'files' not in manifest:
            return None, None
        
        for file_entry in manifest['files']:
            if file_entry.get('url') == glb_filename:
                return file_entry.get('sha256'), file_entry.get('key')
        
        return None, None
    except Exception as e:
        print(f"Warning: Could not read assets.json: {e}", file=sys.stderr)
        return None, None


def format_bytes(num_bytes):
    """Format bytes as human-readable string."""
    for unit in ['B', 'KB', 'MB', 'GB']:
        if num_bytes < 1024.0:
            return f"{num_bytes:.1f} {unit}"
        num_bytes /= 1024.0
    return f"{num_bytes:.1f} TB"


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(2)
    
    glb_path = sys.argv[1]
    
    if not os.path.isfile(glb_path):
        print(f"Error: File not found: {glb_path}", file=sys.stderr)
        sys.exit(2)
    
    # Parse arguments
    expected_sha256 = None
    assets_json_path = None
    
    i = 2
    while i < len(sys.argv):
        if sys.argv[i] == '--sha256' and i + 1 < len(sys.argv):
            expected_sha256 = sys.argv[i + 1].lower()
            i += 2
        elif sys.argv[i] == '--assets-json' and i + 1 < len(sys.argv):
            assets_json_path = sys.argv[i + 1]
            i += 2
        else:
            print(f"Warning: Unknown argument: {sys.argv[i]}", file=sys.stderr)
            i += 1
    
    # If assets.json provided but no explicit hash, try to find it
    glb_filename = os.path.basename(glb_path)
    file_key = None
    if assets_json_path and not expected_sha256:
        expected_sha256, file_key = find_expected_hash_in_assets(assets_json_path, glb_filename)
    
    # Validate header
    print(f"Validating: {glb_path}")
    print(f"Filename: {glb_filename}")
    if file_key:
        print(f"Asset key: {file_key}")
    print()
    
    header = validate_glb_header(glb_path)
    
    print("=== GLB Header ===")
    print(f"Magic:            {header['magic']}")
    print(f"Version:          {header['version']}")
    print(f"Declared length:  {header['declared_length']:,} bytes ({format_bytes(header['declared_length'])})")
    print(f"Actual size:      {header['actual_size']:,} bytes ({format_bytes(header['actual_size'])})")
    print(f"Header valid:     {'✓ PASS' if header['valid'] else '✗ FAIL'}")
    
    if header['error']:
        print(f"Error:            {header['error']}")
    
    print()
    
    # Compute and validate SHA-256
    print("=== SHA-256 ===")
    print("Computing hash...", end='', flush=True)
    actual_sha256 = compute_sha256(glb_path)
    print(f" done")
    print(f"Actual:           {actual_sha256}")
    
    if expected_sha256:
        print(f"Expected:         {expected_sha256}")
        sha256_valid = (actual_sha256 == expected_sha256)
        print(f"SHA-256 match:    {'✓ PASS' if sha256_valid else '✗ FAIL'}")
    else:
        print("Expected:         (not provided)")
        sha256_valid = None
    
    print()
    
    # Overall result
    print("=== Result ===")
    
    checks = {
        'GLB header': header['valid'],
    }
    
    if sha256_valid is not None:
        checks['SHA-256'] = sha256_valid
    
    all_passed = all(checks.values())
    
    for check_name, passed in checks.items():
        status = '✓ PASS' if passed else '✗ FAIL'
        print(f"{check_name:20s} {status}")
    
    print()
    
    if all_passed:
        print("✓ All validations passed")
        sys.exit(0)
    else:
        print("✗ Validation failed")
        sys.exit(1)


if __name__ == '__main__':
    main()
