# অ্যাডমিন অ্যাপ — ডিভাইস স্মোক টেস্ট নির্দেশনা (ADB USB ও Wireless Debugging)

লক্ষ্য: `android-admin`-এর বানানো `app-debug.apk` বাস্তব ফোনে চালিয়ে **লগইন → ড্যাশবোর্ড → লগআউট** যাচাই করা।
`BuildConfig.BASE_URL` (`https://allseba.online/`) বদলের পর এখনো কোনো রানটাইম ভেরিফিকেশন হয়নি, তাই এটি অগ্রাধিকার।

---

## ১. বর্তমান অবস্থা — যা ইতিমধ্যে যাচাই হয়েছে

### বিল্ড ও চেক (সব সত্যিকারের কমান্ড, exit status সংরক্ষিত)

| কমান্ড | ফলাফল |
|---|---|
| `./gradlew assembleDebug` | পাস — `app-debug.apk` (২২ MB), `com.aliftools.admin.debug` `1.0.0-debug` |
| `./gradlew testDebugUnitTest` | পাস — ২৮ টেস্ট, ০ ব্যর্থ (AuthInterceptor 4, ApiParsing 9, AdminRepository 15) |
| `./gradlew lintDebug` | পাস — ০ এরর, ৮৬ ওয়ার্নিং (লোকাল `local.properties`-এর `sdk.dir` escape করে ঠিক করা হয়েছে; কোনো baseline/suppress নয়) |
| `./gradlew assembleRelease` | পাস — `app-release-unsigned.apk` (৩.১ MB, R8); **সইকৃত নয়** (কী-স্টোর নেই) |

### API-স্তরের যাচাই (ফোন ছাড়াই, বিল্ডের `BASE_URL` থেকে ঠিক অ্যাপের পাথ)

| Endpoint | ফলাফল |
|---|---|
| `POST /api/auth/login` (খালি বডি) | `422` — পাথ লাইভ, ভ্যালিডেশন ট্রিগার হচ্ছে |
| `GET /api/admin/dashboard` (টোকেন ছাড়া) | `401` `{"success":false,"message":"Authentication required.",...}` |
| `GET /api/app/version` | `200` বৈধ JSON |

APK-এর dex-এ `https://allseba.online/` আছে এবং `your-domain.com` placeholder নেই — বাইনারি-স্তরে যাচাই করা।
`AdminRepository` নন-2xx/প্লেইন-টেক্সট রেসপন্সকে `HTTP <code>` হিসেবে ধরে, ক্র্যাশ করে না।

---

## ২. পূর্বশর্ত

- **USB পথ:** অ্যান্ড্রয়েড ৮+ (মিনিমাম SDK ২৬) যেকোনো ফোন, শুধু ডেটা-সাপোর্টিং কেবল ও USB debugging লাগে। Wi-Fi লাগে না।
- **ওয়্যারলেস পথ:** ফোন **Android 11 বা তার বেশি** এবং ফোন-কম্পিউটার **একই Wi-Fi নেটওয়ার্কে** (মিটার/হটস্পট/ভিপিএন নয়)।
- এই মেশিনে: JDK 17 (`C:\Users\Alif\tools\jdk17`), Android SDK (`C:\Users\Alif\tools\android-sdk`), adb 37.0.1 (`platform-tools/adb.exe`, mDNS সাপোর্ট সহ)।
- **Emulator ইনস্টল করা নেই** — তাই একমাত্র পথ বাস্তব ফোন।
- অ্যাপটি কেবল একই নেটওয়ার্ক থেকেই নয়, ইন্টারনেট থেকে `https://allseba.online/`-এ যাবে (HTTPS-only)।

---

## ৩. ধাপ ১ — ডিভাইস সংযোগ (একটি পথ বেছে নিন: ক বা খ)

### ক) USB কেবল দিয়ে — সবচেয়ে নির্ভরযোগ্য ও দ্রুত (Android ৮+ যেকোনো ফোন)

**ফোনে:**

1. Settings → About phone → **Build number** ৭ বার ট্যাপ করে Developer options চালু করুন (না থাকলে)।
2. Settings → System → **Developer options → USB debugging → ON**।
3. **ডেটা-সাপোর্টিং** USB কেবল দিয়ে পিসিতে লাগান — শুধু চার্জিং কেবল দিলে ডিভাইস দেখাবে না।
4. ফোনে **"Allow USB debugging?"** পপআপ দেখলে **Allow** চাপুন (চাইলে "Always allow from this computer" টিক দিন)। স্ক্রিন আনলক থাকতে হবে।
5. USB মোড **File transfer / MTP**-তে রাখুন — কিছু ফোন charge-only মোডে ADB লুকিয়ে দেয়।

**পিসিতে যাচাই:**

```bash
ADB=/c/Users/Alif/tools/android-sdk/platform-tools/adb.exe
"$ADB" devices -l        # একটি লাইন state=device দেখা উচিত; unauthorized/offline নয়
```

`state=device` পেলেই সরাসরি §৪-এর ইনস্টল/লঞ্চ কমান্ডে যান — **পেয়ারিং বা `adb connect` লাগবে না**।

USB থেকে কেবল খুলেও ওয়্যারলেসে চালিয়ে যেতে চাইলে (পুরনো পদ্ধতি, সব ভার্সনে চলে):

```bash
"$ADB" tcpip 5555                       # কেবল লাগানো অবস্থায় একবার
"$ADB" connect <ফোনের-IP>:5555         # এরপর কেবল খুলে দিলেও চলবে
```

### খ) ওয়্যারলেস ডিবাগিং দিয়ে (Android 11+, কেবল ছাড়া)

1. Settings → About phone → **Build number** ৭ বার ট্যাপ করে Developer options চালু করুন (না থাকলে)।
2. Settings → System → **Developer options → Wireless debugging → ON**।
3. সেই প্যানেলে **"Pair device with pairing code"** চাপুন।
4. দেওয়া হবে: ৬-সংখ্যার **pairing code** এবং `IP:PORT` (যেমন `192.168.1.7:37125`)।
5. সেই দুটো এজেন্ট/টার্মিনালে পাঠান। পাসওয়ার্ড/অ্যাকাউন্ট ক্রেডেনশিয়াল **কখনও পাঠাবেন না** (নিচে §৬)।

---

## ৪. ধাপ ২ — পিসি থেকে সংযোগ, ইনস্টল, লঞ্চ (আমার কাজ)

USB পথে শুধু `devices` / `install` / `am start` — ওয়্যারলেস পথে `pair` + `connect`-ও লাগে:

```bash
ADB=/c/Users/Alif/tools/android-sdk/platform-tools/adb.exe

# --- ওয়্যারলেস পথ (USB হলে এই তিনটি লাগবে না) ---
"$ADB" mdns services                       # ফোন আগে থেকে দেখা গেলে সরাসরি যাবে
"$ADB" pair 192.168.1.7:37125 123456        # IP:PORT + ৬-সংখ্যার কোড
"$ADB" connect 192.168.1.7:PORT             # পেয়ারিং-পরবর্তী কানেক্ট পোর্ট (আলাদা)

"$ADB" devices -l                           # উভয় পথেই state=device হতে হবে

cd android-admin
JAVA_HOME=/c/Users/Alif/tools/jdk17 ./gradlew assembleDebug   # নতুন APK দরকার হলে
"$ADB" install -r app/build/outputs/apk/debug/app-debug.apk

"$ADB" shell am start -n com.aliftools.admin.debug/com.aliftools.admin.MainActivity
```

লগ ধরে রাখা (ক্র্যাশ/নেটওয়ার্ক ধরার জন্য):

```bash
"$ADB" logcat -c
"$ADB" logcat -v time | grep -iE "aliftools|retrofit|okhttp|AndroidRuntime|FATAL"
```

---

## ৫. ধাপ ৩ — টেস্ট চেকলিস্ট

প্রতিটি ধাপ `uiautomator dump` অথবা `adb exec-out screencap -p > shot.png` দিয়ে প্রমাণ নিয়ে যাচাই করো:

- [ ] অ্যাপ ইনস্টল ও লঞ্চ হয়, ক্র্যাশ নেই (`logcat`-এ `FATAL` নেই)।
- [ ] Splash → লগইন স্ক্রিন রেন্ডার হয়; কোনো placeholder/`your-domain.com` দেখা যায় না।
- [ ] ভুল পাসওয়ার্ডে ত্রুটি মেসেজ আসে (সার্ভারের এনভেলপ অনুযায়ী), অ্যাপ ক্র্যাশ করে না।
- [ ] সঠিক লগইনে টোকেন সেভ হয় ও ড্যাশবোর্ড লোড হয় (ডেটা `https://allseba.online/` থেকেই, ক্যাশ নয়)।
- [ ] টুলবারের লগআউট আইকন দেখা যায় — এটিই নতুন `Icons.AutoMirrored.Filled.Logout`।
- [ ] লগআউট → সেশন ক্লিয়ার → ব্যাক বাটন দিয়ে আগের স্ক্রিনে ফিরে যাওয়া যায় না।
- [ ] পুনরায় লগইন করলে রিফ্রেশ-টোকেন রোটেশন ও 401 হ্যান্ডলিং ঠিক আছে।
- [ ] টোকেন মুছে ফেলে (যদি সম্ভব হয়) পরবর্তী কল 401 দিলে অ্যাপ অটো-লগআউট করে।

---

## ৬. ক্রেডেনশিয়াল নীতি (গুরুত্বপূর্ণ)

- **চ্যাটে পাসওয়ার্ড বা PIN পাঠানো যাবে না**, নতুন কোনো সিক্রেট ফাইলেও রাখা যাবে না।
- লগইন ধাপে পাসওয়ার্ড **আপনি নিজে ফোনের স্ক্রিনে** টাইপ করবেন; এজেন্ট শুধু UI/নেটওয়ার্ক যাচাই করবে।
- প্রোডাকশন অ্যাকাউন্টের বদলে টেস্ট অ্যাডমিন অ্যাকাউন্ট থাকলে সেটি ব্যবহার করা নিরাপদ।
- সোর্স/ডকে `BuildConfig`-এ কখনও ক্রেডেনশিয়াল বসানো যাবে না; `local.properties` ও `keystore.properties` gitignored থাকবে।

---

## ৭. সমস্যা-সমাধান

| সমস্যা | সমাধান |
|---|---|
| `adb mdns services` খালি থাকে | ফোনে Wireless debugging বন্ধ আছে, অথবা রাউটার mDNS ব্লক করেছে — পেয়ারিং স্ক্রিনের `IP:PORT` ম্যানুয়ালি দিয়ে `adb pair` চালান, এরপর কানেক্ট পোর্ট দিয়ে `adb connect`। |
| `adb devices`-এ `unauthorized` | ফোনে "Allow USB debugging" পপআপে Allow চাপুন; বারবার হলে Developer options → **Revoke USB debugging authorizations** দিয়ে রিসেট করে আবার অনুমতি দিন। |
| USB থাকা সত্ত্বেও `adb devices` খালি | কেবল ডেটা-সাপোর্টিং কিনা চেক করুন, USB মোড **File transfer** করুন, ফোন আনলক রাখুন, ফের `adb kill-server` পর আবার দেখুন; Windows-এ Device Manager-এ অজানা ডিভাইস থাকলে Google USB/OEM ড্রাইভার লাগতে পারে। |
| USB কেবল খুললেই ডিভাইস উইথড্র | স্঵াভাবিক — কেবল লাগানো অবস্থায় `adb tcpip 5555` চালিয়ে ওয়্যারলেসে যান, অথবা Android 11+ Wireless debugging ব্যবহার করুন। |
| `offline` দেখায় | `adb kill-server` তারপর আবার `adb connect` (বা কেবল বের করে লাগান)। |
| পেয়ারিং ম্যাচ করে না | কোড ৩০ সেকেন্ডে মিলিয়ে যায় — নতুন কোড নিন; দুটোই একই Wi-Fi-তে আছে কিনা দেখুন। |
| পুরনো Android (<11) | ওয়্যারলেস ডিবাগিং নেই — একবার USB লাগিয়ে `adb tcpip 5555` তারপর `adb connect <ip>:5555`, অথবা USB-তেই টেস্ট করুন। |
| কিছু OEM (Xiaomi/Realme ইত্যাদি) | ওয়্যারলেস ডিবাগিং ছাড়া USB debugging/Install via USB-ও চালু রাখতে হতে পারে। |
| APK ইনস্টল রিজেক্ট | `adb install -r` (সেইম সিগনেচার) ব্যবহার করুন; signature ভিন্ন হলে আগে `adb uninstall com.aliftools.admin.debug`। |

---

## ৮. সীমাবদ্ধতা ও পরবর্তী ধাপ

- রিলিজ APK **unsigned** — সাইনিংয়ের জন্য `keystore.properties` + `.jks` লাগবে (git ছাড়া)।
- CI (GitHub Actions)-এ `ADMIN_BASE_URL` সেট নেই, তাই সেখানে বানানো APK-তে placeholder বসবে।
- `lintDebug`-এ ৮৬টি ওয়ার্নিং বাকি (এরর নয়); CI-তে lint `continue-on-error`।
- `androidTest` (ইন্ট্রুমেন্টেড) চলেনি — ডিভাইস/এমুলেটর লাগে।
- কোনো কমিট করা হয়নি; কাজ শেষে `git status --short` দেখে শুধু নিজের পরিবর্তিত ফাইল স্টেজ করুন।
