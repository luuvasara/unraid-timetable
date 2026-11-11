/* Timetable: shows one day of the timeline at a time (all 7 stay in the page for print and without JavaScript),
 * remembers the day picked while the page is open, and sends Scheduler links to the right tab. Nothing is sent to
 * the server and nothing is changed. */
(function () {
  'use strict';
  var root = document.getElementById('tt-timeline');
  if (root && !root.dataset.ttStarted) {
    root.dataset.ttStarted = '1';
    var pick = root.querySelector('.tt-daypick');
    var days = root.querySelectorAll('.tt-day');
    var buttons = pick ? pick.querySelectorAll('.tt-dp') : [];
    var show = function (n) {
      for (var i = 0; i < days.length; i++) days[i].hidden = String(i) !== String(n);
      for (var k = 0; k < buttons.length; k++) {
        var on = buttons[k].getAttribute('data-day') === String(n);
        buttons[k].setAttribute('aria-selected', on ? 'true' : 'false');
        buttons[k].tabIndex = on ? 0 : -1;
      }
    };
    if (pick && days.length) {
      root.classList.add('tt-js');
      pick.hidden = false;
      show(0);
      pick.addEventListener('click', function (e) {
        var b = e.target.closest('.tt-dp');
        if (b) show(b.getAttribute('data-day'));
      });
      pick.addEventListener('keydown', function (e) {
        var cur = Array.prototype.indexOf.call(buttons, document.activeElement);
        if (cur < 0) return;
        var next = e.key === 'ArrowRight' ? cur + 1 : e.key === 'ArrowLeft' ? cur - 1 : e.key === 'Home' ? 0 : e.key === 'End' ? buttons.length - 1 : null;
        if (next === null) return;
        e.preventDefault();
        next = (next + buttons.length) % buttons.length;
        show(buttons[next].getAttribute('data-day'));
        buttons[next].focus();
      });
      // on a narrow screen, start today's chart scrolled so the now line is in view
      var scrollers = root.querySelectorAll('.tt-day .tt-scroll');
      for (var s = 0; s < scrollers.length; s++) {
        var now = scrollers[s].querySelector('.tt-now');
        if (now && scrollers[s].scrollWidth > scrollers[s].clientWidth) {
          var x = now.getBoundingClientRect().left - scrollers[s].getBoundingClientRect().left;
          scrollers[s].scrollLeft = Math.max(0, x - scrollers[s].clientWidth * 0.5);
        }
      }
    }
  }
  // Settings > Scheduler opens the tab remembered in the cookie "one"; set it to the job's tab (core does the same)
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('.tt a.tt-go[data-tab]');
    if (!a) return;
    try { document.cookie = 'one=' + encodeURIComponent(a.getAttribute('data-tab')) + '; path=/'; } catch (err) { /* cookies off: the page opens on its last tab */ }
  });
})();
