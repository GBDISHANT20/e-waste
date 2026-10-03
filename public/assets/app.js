// Progressive enhancement only: every page works without JavaScript.
(function () {
  'use strict';

  // Citizen sign-up: show ward OR village, and only the ones of the chosen district.
  var form = document.getElementById('citizen-form');
  if (!form) return;
  var district = form.elements.district_id;
  var areaType = form.elements.area_type;
  var groups = form.querySelectorAll('[data-for]');

  function sync() {
    groups.forEach(function (g) {
      var on = g.getAttribute('data-for') === areaType.value;
      var sel = g.querySelector('select');
      g.hidden = !on;
      sel.disabled = !on;
      sel.required = on;
      Array.prototype.forEach.call(sel.options, function (o) {
        if (!o.value) return;
        var show = !district.value || o.getAttribute('data-district') === district.value;
        o.hidden = !show;
        o.disabled = !show;
        if (!show && o.selected) sel.value = '';
      });
    });
  }
  district.addEventListener('change', sync);
  areaType.addEventListener('change', sync);
  sync();
})();
