// SMARTPRS 3 IN THE MAIN THEME (owner, 20 Nov 2026: "change complete SmartPRS page"). SmartPRS hard-codes its orange,
// amber and blue in ~140 places (its stylesheet, <style> blocks inside pages, inline styles). Inside Ametecs Global this
// swaps each of them for Ametecs Global's gold / dark gold, once when the page loads and again for anything added later.
// Green (success) and red (errors) are left alone on purpose. Colours only - nothing about the page's behaviour changes.
(function () {
  var MAP = {   // SmartPRS colour -> Ametecs Global colour (r,g,b)
    '249,115,22': '201,162,76', '251,146,60': '214,174,85', '234,88,12': '176,132,51', '194,65,12': '138,100,32',
    '253,186,116': '232,201,119', '255,237,213': '247,236,210', '255,247,237': '255,250,240',
    '245,158,11': '201,162,76', '217,119,6': '176,132,51', '251,191,36': '214,174,85', '254,243,199': '247,236,210', '255,251,235': '255,250,240',
    '37,99,235': '138,100,32', '59,130,246': '176,132,51', '29,78,216': '107,77,22', '96,165,250': '214,174,85', '147,197,253': '232,201,119',
    '191,219,254': '236,220,182', '219,234,254': '247,236,210', '239,246,255': '250,246,238',
    // module colours (purple, violet, teal, cyan, indigo, pink) and their dark tints -> gold / dark gold
    '124,58,237': '176,132,51', '139,92,246': '201,162,76', '168,85,247': '214,174,85', '109,40,217': '138,100,32', '196,181,253': '232,201,119', '237,233,254': '247,236,210', '245,243,255': '255,250,240',
    '13,148,136': '176,132,51', '20,184,166': '201,162,76', '15,118,110': '138,100,32', '204,251,241': '247,236,210', '240,253,250': '255,250,240',
    '8,145,178': '176,132,51', '6,182,212': '201,162,76', '207,250,254': '247,236,210', '236,254,255': '255,250,240',
    '99,102,241': '201,162,76', '79,70,229': '176,132,51', '67,56,202': '138,100,32', '224,231,255': '247,236,210', '238,242,255': '255,250,240',
    '236,72,153': '201,162,76', '219,39,119': '176,132,51', '252,231,243': '247,236,210', '253,242,248': '255,250,240',
    '153,60,29': '107,77,22', '8,80,65': '107,77,22', '38,33,92': '107,77,22', '161,98,7': '138,100,32',
    // SmartPRS's dark-mode navy -> Ametecs Global's warm black (only ever used in dark mode)
    // found by sweeping SmartPRS's menu pages (20 Nov 2026): its navy headings/text, light blue and pink washes, dark tints
    '30,58,95': '23,19,12', '31,58,95': '23,19,12', '15,23,42': '23,19,12', '10,23,48': '16,13,8', '15,29,51': '16,13,8', '12,68,124': '107,77,22',
    '230,241,251': '250,246,238', '238,242,248': '250,248,244', '250,236,231': '255,250,240', '147,51,234': '176,132,51', '180,83,9': '138,100,32',
    '22,19,52': '33,28,19', '28,15,51': '29,24,16', '33,10,20': '29,24,16',
    '19,28,43': '23,19,13', '11,18,32': '13,11,8', '37,51,73': '46,38,24', '12,25,41': '16,13,8', '30,42,61': '33,28,19', '26,37,54': '29,24,16', '31,44,64': '34,28,19', '7,12,21': '10,8,5'
  };
  var hex = {}, rgbRe = [];
  Object.keys(MAP).forEach(function (k) {
    var a = k.split(','), b = MAP[k].split(',');
    var h = function (p) { return '#' + p.map(function (n) { return ('0' + (+n).toString(16)).slice(-2); }).join(''); };
    hex[h(a)] = h(b);
    rgbRe.push([new RegExp('\\b' + a.join('\\s*,\\s*') + '\\b', 'g'), b.join(', ')]);
  });
  var hexRe = new RegExp(Object.keys(hex).join('|'), 'gi');
  function swap(s) {
    if (!s) return s;
    var t = s.replace(hexRe, function (m) { return hex[m.toLowerCase()]; });
    for (var i = 0; i < rgbRe.length; i++) { t = t.replace(rgbRe[i][0], rgbRe[i][1]); }
    return t;
  }
  function rules(list) {
    for (var i = 0; i < list.length; i++) {
      var r = list[i];
      if (r.cssRules) { rules(r.cssRules); }
      if (!r.style) continue;
      for (var j = 0; j < r.style.length; j++) {
        var p = r.style[j], v = r.style.getPropertyValue(p), n = swap(v);
        if (n !== v) r.style.setProperty(p, n, r.style.getPropertyPriority(p));
      }
    }
  }
  function sheets() {
    for (var i = 0; i < document.styleSheets.length; i++) {
      var sh = document.styleSheets[i];
      if (sh.__ag) continue;
      try { rules(sh.cssRules); sh.__ag = 1; } catch (e) {}   // another site's sheet (fonts): left alone
    }
  }
  function inline(root) {
    (root.querySelectorAll ? root.querySelectorAll('[style]') : []).forEach(function (el) {
      var v = el.getAttribute('style'), n = swap(v); if (n !== v) el.setAttribute('style', n);
    });
    if (root.getAttribute && root.getAttribute('style')) { var v = root.getAttribute('style'), n = swap(v); if (n !== v) root.setAttribute('style', n); }
  }
  function all() { sheets(); inline(document); }
  all();
  document.addEventListener('DOMContentLoaded', all);
  addEventListener('load', all);
  new MutationObserver(function (ms) {   // pages that draw parts later (filters, tabs, pop-ups)
    var more = false;
    ms.forEach(function (m) {
      m.addedNodes.forEach(function (n) { if (n.nodeType === 1) { inline(n); if (n.tagName === 'STYLE' || n.tagName === 'LINK' || n.querySelector('style,link')) more = true; } });
      if (m.type === 'attributes' && m.target.getAttribute) { var v = m.target.getAttribute('style'), s = swap(v); if (s !== v) m.target.setAttribute('style', s); }
    });
    if (more) setTimeout(sheets, 50);
  }).observe(document.documentElement, {childList: true, subtree: true, attributes: true, attributeFilter: ['style']});
})();
