# WordPress Site Detector (Chrome Extension)

একটি Chrome extension যা যেকোনো ওয়েবসাইট WordPress দিয়ে তৈরি কিনা তা শনাক্ত করে।

## কীভাবে চেক করে

Extension টি প্রতিটি সাইটের জন্য একাধিক সিগন্যাল পরীক্ষা করে স্কোর হিসাব করে:

- `<meta name="generator" content="WordPress ...">` ট্যাগ
- HTML-এ `wp-content/` এবং `wp-includes/` পাথ
- `<link rel="https://api.w.org/">` (REST API discovery)
- `/wp-json/` REST API রুট এবং তার `wp/v2` namespace
- `wp-emoji` স্ক্রিপ্ট/সেটিংস
- `readme.html` ফাইলে WordPress-এর signature টেক্সট
- `xmlrpc.php`-এর প্রতিক্রিয়া

প্রতিটি সিগন্যালের একটি ওজন (weight) আছে; মোট স্কোরের ভিত্তিতে ফলাফল তিন ভাগে ভাগ করা হয়:

| স্কোর | ফলাফল |
| --- | --- |
| ৪০ বা তার বেশি | ✅ WordPress সাইট |
| ১৫–৩৯ | ❓ নিশ্চিত নয় |
| ১৫-এর কম | ❌ WordPress নয় |

## ইনস্টল করার নিয়ম (Load unpacked)

1. Chrome-এ যান: `chrome://extensions`
2. উপরে ডান দিকে **Developer mode** চালু করুন
3. **Load unpacked** বাটনে ক্লিক করুন
4. এই `wordpress-detector-extension` ফোল্ডারটি সিলেক্ট করুন
5. Toolbar-এ extension আইকনে ক্লিক করে ব্যবহার শুরু করুন

## ব্যবহার

- **বর্তমান ট্যাব**: এক ক্লিকেই আপনি যে সাইটে আছেন সেটি চেক করুন
- **যেকোনো সাইট চেক করুন**: একটি input বক্সে ডোমেইন বা URL লিখে চেক করুন (যেমন `example.com`)
- **একাধিক সাইট চেক করুন**: টেক্সটবক্সে একাধিক ডোমেইন/URL (প্রতি লাইনে একটি) পেস্ট করে একসাথে চেক করুন, ফলাফল টেবিলে দেখুন এবং **CSV ডাউনলোড** বাটনে ফলাফল এক্সপোর্ট করুন
- **টুলবার ব্যাজ**: প্রতিটি পেজ লোড হওয়ার সাথে সাথে extension আইকনে অটোমেটিক সবুজ (`WP`) বা হলুদ (`?`) ব্যাজ দেখাবে যদি সাইটটি WordPress/অনিশ্চিত মনে হয় (দ্রুত, শুধু হোমপেজ চেক করে — বাড়তি রিকোয়েস্ট ছাড়া)
- **রাইট-ক্লিক মেনু**: যেকোনো লিংকে রাইট-ক্লিক করে "এই লিংকটি WordPress কিনা চেক করুন" সিলেক্ট করলে একটি ডেস্কটপ notification-এ ফলাফল দেখাবে
- **ইতিহাস**: আগে চেক করা সাইটগুলোর ফলাফল favicon-সহ দেখুন (Chrome local storage-এ সংরক্ষিত), প্রয়োজনে মুছে ফেলুন
- **ডার্ক মোড**: সিস্টেম থিম dark হলে popup automatically dark দেখাবে

## ফাইল গঠন

```
wordpress-detector-extension/
├── manifest.json      # Manifest V3 কনফিগারেশন
├── background.js      # Service worker, ডিটেকশন রিকোয়েস্ট হ্যান্ডেল করে
├── detector.js         # মূল ডিটেকশন লজিক (স্কোরিং সিস্টেম)
├── popup.html/css/js  # Extension popup UI
└── icons/              # Extension আইকন
```

## সীমাবদ্ধতা

- কিছু সাইট bot-protection (Cloudflare, WAF ইত্যাদি) ব্যবহার করলে fetch ব্যর্থ হতে পারে, যার ফলে ফলাফল কম নির্ভরযোগ্য হতে পারে।
- এটি heuristic-ভিত্তিক শনাক্তকরণ, তাই ১০০% নিশ্চয়তা দেয় না।
