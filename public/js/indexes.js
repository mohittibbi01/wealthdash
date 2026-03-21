/**
 * WealthDash - Market Indexes JS
 * Colored heatmap tiles with SVG sparklines
 */
'use strict';

(function () {

    var REFRESH_SEC = 60;
    var autoOn      = true;
    var countdown   = REFRESH_SEC;
    var cdTimer     = null;

    function $id(id) { return document.getElementById(id); }

    function fmtNum(n, dec) {
        dec = dec === undefined ? 2 : dec;
        if (n === null || n === undefined || isNaN(n)) return '--';
        return Number(n).toLocaleString('en-IN', {
            minimumFractionDigits: dec,
            maximumFractionDigits: dec
        });
    }

    function buildSparkline(pts, isGreen) {
        if (!pts || pts.length < 2) return '';
        var W = 130, H = 34;
        var min = Math.min.apply(null, pts);
        var max = Math.max.apply(null, pts);
        var range = max - min || 1;
        var coords = pts.map(function(v, i) {
            var x = (i / (pts.length - 1)) * W;
            var y = H - ((v - min) / range) * (H - 4) - 2;
            return x.toFixed(1) + ',' + y.toFixed(1);
        });
        var last = coords[coords.length - 1].split(',');
        var areaD = 'M ' + coords.join(' L ') + ' L ' + last[0] + ',' + H + ' L 0,' + H + ' Z';
        return '<svg width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none">' +
            '<path d="' + areaD + '" fill="rgba(255,255,255,0.15)" stroke="none"/>' +
            '<polyline points="' + coords.join(' ') + '" fill="none" stroke="rgba(255,255,255,0.9)" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/>' +
            '<circle cx="' + last[0] + '" cy="' + last[1] + '" r="2.5" fill="rgba(255,255,255,0.95)"/>' +
            '</svg>';
    }

    function renderTile(idx) {
        var up   = idx.change !== null && idx.change >= 0;
        var down = idx.change !== null && idx.change < 0;
        var cls  = idx.price === null ? 'neutral' : (up ? 'green' : 'red');
        var arrow = up ? '\u25b2' : (down ? '\u25bc' : '');

        var priceStr = idx.price !== null ? fmtNum(idx.price) : '--';
        var chStr    = '--';
        if (idx.change !== null) {
            var s = idx.change >= 0 ? '+' : '';
            chStr = arrow + ' ' + s + fmtNum(idx.change) + ' (' + s + fmtNum(idx.change_pct) + '%)';
        }

        var spark = buildSparkline(idx.sparkline || [], up);

        return '<div class="idx-tile ' + cls + '">' +
            '<div class="t-name">' + idx.label + '</div>' +
            '<div class="t-price">' + priceStr + '</div>' +
            '<div class="t-change">' + chStr + '</div>' +
            '<div class="t-spark">' + spark + '</div>' +
            '</div>';
    }

    function renderAll(data) {
        var sections = ['india_broad', 'india_sectoral', 'world', 'commodities'];
        var green = 0, red = 0;

        sections.forEach(function(sec) {
            var el   = $id('g-' + sec);
            var list = (data.indexes && data.indexes[sec]) ? data.indexes[sec] : [];
            if (!el) return;
            el.innerHTML = list.map(function(idx) {
                if (idx.change !== null) { idx.change >= 0 ? green++ : red++; }
                return renderTile(idx);
            }).join('');
        });

        // Summary bar
        var sumEl = $id('idx-summary');
        if (sumEl) {
            var total   = green + red;
            var breadth = total > 0 ? Math.round(green / total * 100) : 0;
            sumEl.innerHTML =
                '<span class="badge badge-green">\u25b2 ' + green + ' Green</span>' +
                '<span class="badge badge-red">\u25bc ' + red + ' Red</span>' +
                '<span style="font-size:12px;color:var(--text-muted);">' + breadth + '% advancing</span>';
        }

        // NSE badge
        var nseEl = $id('nse-badge');
        if (nseEl) {
            nseEl.innerHTML = data.nse_open
                ? '<span class="badge badge-green" style="font-size:11px;">&#9679; NSE Open</span>'
                : '<span class="badge badge-gray" style="font-size:11px;">&#9679; NSE Closed</span>';
        }

        var luEl = $id('idx-updated');
        if (luEl) luEl.textContent = data.fetched_at || '';
    }

    function fetchAll(silent) {
        if (!silent) {
            ['india_broad', 'india_sectoral', 'world', 'commodities'].forEach(function(s) {
                var el = $id('g-' + s);
                if (el) el.innerHTML = '<div class="idx-tile neutral" style="grid-column:1/-1;opacity:.5"><div class="t-name">Fetching...</div></div>';
            });
        }

        var btn = $id('btnRefresh');
        if (btn) btn.disabled = true;

        apiPost({ action: 'indexes_fetch' })
            .then(function(res) {
                if (res && res.success) {
                    renderAll(res.data);
                    if (res.data && res.data.failed_count > 0) {
                        showToast('Some indexes failed to load.', 'warning');
                    }
                } else {
                    showToast('Could not fetch market data.', 'error');
                }
            })
            .catch(function() { showToast('Network error fetching indexes.', 'error'); })
            .finally(function() {
                if (btn) btn.disabled = false;
                countdown = REFRESH_SEC;
            });
    }

    function startCountdown() {
        clearInterval(cdTimer);
        cdTimer = setInterval(function() {
            var el = $id('idx-cd');
            if (autoOn) {
                countdown--;
                if (el) el.textContent = 'Auto-refresh in ' + countdown + 's';
                if (countdown <= 0) { countdown = REFRESH_SEC; fetchAll(true); }
            } else {
                if (el) el.textContent = 'Auto-refresh off';
            }
        }, 1000);
    }

    function init() {
        fetchAll(false);
        startCountdown();

        var btnR = $id('btnRefresh');
        if (btnR) btnR.addEventListener('click', function() { countdown = REFRESH_SEC; fetchAll(false); });

        var btnT = $id('btnToggleAR');
        if (btnT) btnT.addEventListener('click', function() {
            autoOn = !autoOn;
            btnT.textContent = autoOn ? '\u23f8 Auto-refresh' : '\u25b6 Resume';
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();