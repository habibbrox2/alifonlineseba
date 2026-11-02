#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Admin Android App Screenshot Capture Tool
G:\Web\allseba-online\screenshorts\admin-android এ স্ক্রিনশট সেভ করে
"""

import os
import sys
import time
from datetime import datetime
from pathlib import Path

try:
    import subprocess
    import re
except ImportError:
    pass

OUTPUT_DIR = Path(r"G:\Web\allseba-online\screenshorts\admin-android")
PACKAGE_NAME = "com.aliftools.admin"

def check_adb():
    """Check if adb is available"""
    try:
        result = subprocess.run(["adb", "version"], capture_output=True, text=True)
        if result.returncode == 0:
            print("✅ ADB পাওয়া গেছে")
            return True
    except FileNotFoundError:
        pass
    print("❌ ADB পাওয়া যায়নি। Android Platform Tools ইনস্টল করুন।")
    return False

def check_devices():
    """Check connected devices"""
    try:
        result = subprocess.run(["adb", "devices"], capture_output=True, text=True)
        devices = re.findall(r'(\S+)\s+device$', result.stdout, re.M)
        if devices:
            print(f"✅ {len(devices)}টি ডিভাইস সংযুক্ত আছে")
            for d in devices:
                print(f"   - {d}")
            return devices
        else:
            print("❌ কোনো ডিভাইস সংযুক্ত নেই")
            print("   USB ডিবাগিং চালু করে ডিভাইস কানেক্ট করুন")
            return []
    except:
        return []

def get_current_activity():
    """Get current activity name"""
    try:
        result = subprocess.run(
            ["adb", "shell", "dumpsys", "activity", "activities", "|", "grep", "-E", "mResumedActivity|topResumedActivity"],
            capture_output=True,
            text=True,
            shell=True
        )
        return result.stdout.strip()
    except:
        return ""

def take_screenshot(output_path):
    """Take screenshot and save to PC"""
    try:
        with open(output_path, 'wb') as f:
            result = subprocess.run(["adb", "exec-out", "screencap", "-p"], stdout=f)
        return result.returncode == 0
    except Exception as e:
        print(f"❌ Error: {e}")
        return False

def main():
    print("=" * 50)
    print("Admin Android App স্ক্রিনশট ক্যাপচার টুল")
    print("=" * 50)
    print()

    # Create output directory
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    print(f"📁 আউটপুট ফোল্ডার: {OUTPUT_DIR}")
    print()

    if not check_adb():
        sys.exit(1)

    devices = check_devices()
    if not devices:
        sys.exit(1)

    print()
    print("বর্তমান অ্যাকটিভিটি:")
    activity = get_current_activity()
    if activity:
        print(f"   {activity}")
    else:
        print("   (পাওয়া যায়নি)")
    print()

    print("=" * 50)
    print("ব্যবহারবিধি:")
    print("=" * 50)
    print("1. অ্যাপে যাওয়ার desired স্ক্রিনে যান")
    print("2. Enter চাপুন স্ক্রিনশট নিতে")
    print("3. 'q' + Enter = প্রস্থান")
    print("4. 'a' + Enter = বর্তমান অ্যাকটিভিটি নামসহ স্ক্রিনশট নিন")
    print("5. 'l' + Enter = ইতিমধ্যে নেয়া স্ক্রিনশট তালিকা দেখুন")
    print()

    counter = len(list(OUTPUT_DIR.glob("*.png"))) + 1

    while True:
        try:
            cmd = input(">>> স্ক্রিনশট নিতে Enter, 'a'=অ্যাকটিভিটি নামসহ, 'l'=তালিকা, 'q'=প্রস্থান: ").strip().lower()

            if cmd == 'q':
                break
            elif cmd == 'l':
                files = sorted(OUTPUT_DIR.glob("*.png"))
                print(f"\n📋 মোট {len(files)}টি স্ক্রিনশট:")
                for f in files[-10:]:  # Show last 10
                    print(f"   - {f.name}")
                if len(files) > 10:
                    print(f"   ... এবং আরও {len(files)-10}টি")
                print()
                continue
            elif cmd == 'a':
                activity = get_current_activity()
                # Extract activity name
                match = re.search(r'([A-Za-z0-9_]+\.[A-Za-z0-9_.]+Activity)', activity)
                if match:
                    act_name = match.group(1).split('.')[-1]
                else:
                    act_name = f"screen{counter:03d}"
                timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
                filename = f"{act_name}_{timestamp}.png"
            else:
                timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
                filename = f"screen_{counter:03d}_{timestamp}.png"

            filepath = OUTPUT_DIR / filename
            print(f"📸 ক্যাপচার করা হচ্ছে: {filename}")

            if take_screenshot(filepath):
                size_kb = filepath.stat().st_size / 1024
                print(f"✅ সেভ হয়েছে! ({size_kb:.1f} KB)")
                counter += 1
            else:
                print("❌ ব্যর্থ হয়েছে")

            print()

        except KeyboardInterrupt:
            break
        except EOFError:
            break

    print("=" * 50)
    print(f"সম্পন্ন! {OUTPUT_DIR} এ স্ক্রিনশট গুলো সংরক্ষিত আছে")
    print("=" * 50)

if __name__ == "__main__":
    main()
