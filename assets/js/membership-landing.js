(function () {
  'use strict';
  document.querySelectorAll('.adam-membership-landing details').forEach(function (item) {
    item.addEventListener('toggle', function () {
      if (!item.open) return;
      document.querySelectorAll('.adam-membership-landing details[open]').forEach(function (other) {
        if (other !== item) other.removeAttribute('open');
      });
    });
  });
}());
