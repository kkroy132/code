# Chart.js

`chart.umd.min.js` in this folder is an unmodified, official production build of Chart.js.

| | |
|---|---|
| **Version** | 4.5.1 |
| **License** | MIT (full text below) |
| **Project home** | https://www.chartjs.org/ |
| **Source repository** | https://github.com/chartjs/Chart.js |
| **Sources for this exact version** | https://github.com/chartjs/Chart.js/tree/v4.5.1 |
| **Unminified build of this version** | https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.js |

## Why the minified build is the one shipped

This is the distribution build published by the Chart.js project itself. It is minified, not
obfuscated, and it has not been modified in any way for this plugin. The unminified equivalent of
the very same build is one click away at the jsDelivr link above, and the sources it was compiled
from are in the tagged release. Shipping the 204 KB minified build instead of the roughly 1 MB
development build keeps the plugin package small and the admin screens quick to load.

To confirm the file is the genuine published build, compare the checksums:

```
curl -sL https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js | shasum -a 256
shasum -a 256 assets/js/vendor/chart.umd.min.js
```

## Where it is used

Only in wp-admin, and only on this plugin's own screens: the "Clicks Over Time" chart on the
Analytics tab, and the charts inside the per-link and per-bio-page analytics modals. It is never
enqueued on the front end, and it makes no network requests of its own.

---

## MIT License

The MIT License (MIT)

Copyright (c) 2014-2024 Chart.js Contributors

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
