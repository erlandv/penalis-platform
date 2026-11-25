(function () {
  'use strict';

  var containers = document.querySelectorAll('[data-penalis-toc="1"]');
  if (!containers.length) {
    return;
  }

  var prefersReducedMotion =
    typeof window.matchMedia === 'function' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function setExpanded(nav, button, body, expanded) {
    nav.classList.toggle('is-expanded', expanded);
    nav.classList.toggle('is-collapsed', !expanded);
    button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    body.hidden = !expanded;

    var labelEl = button.querySelector('.penalis-toc__toggle-label');
    if (labelEl) {
      var showLabel = button.getAttribute('data-label-show') || 'Tampilkan';
      var hideLabel = button.getAttribute('data-label-hide') || 'Sembunyikan';
      labelEl.textContent = expanded ? hideLabel : showLabel;
    }
  }

  for (var i = 0; i < containers.length; i += 1) {
    (function (nav) {
      var toggle = nav.querySelector('.penalis-toc__toggle');
      var body = nav.querySelector('.penalis-toc__body');
      var smooth = nav.getAttribute('data-penalis-toc-smooth') === '1';

      if (toggle && body) {
        toggle.addEventListener('click', function () {
          var isExpanded = toggle.getAttribute('aria-expanded') === 'true';
          setExpanded(nav, toggle, body, !isExpanded);
        });
      }

      if (smooth && !prefersReducedMotion) {
        var links = nav.querySelectorAll('a.penalis-toc__link[href^="#"]');
        for (var j = 0; j < links.length; j += 1) {
          links[j].addEventListener('click', function (event) {
            var href = event.currentTarget.getAttribute('href');
            if (!href || href.length < 2) {
              return;
            }
            var targetId = href.slice(1);
            var target = document.getElementById(targetId);
            if (!target) {
              return;
            }
            event.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            if (window.history && typeof window.history.replaceState === 'function') {
              window.history.replaceState(null, '', '#' + encodeURIComponent(targetId));
            }
          });
        }
      }
    })(containers[i]);
  }
})();
