// Progressive enhancement only: every page works without JavaScript.
(function () {
  'use strict';

  // Forms with district + area type + ward/village: show ward OR village, only those of the chosen district.
  document.querySelectorAll('form[data-area-form]').forEach(function (form) {
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
  });

  // "Use my location" buttons fill latitude / longitude from the device GPS.
  document.querySelectorAll('button[data-geo]').forEach(function (btn) {
    if (!navigator.geolocation) return;
    btn.hidden = false;
    btn.addEventListener('click', function () {
      var names = btn.getAttribute('data-geo').split('|');
      var form = btn.form;
      var label = btn.textContent;
      btn.textContent = 'Locating…';
      navigator.geolocation.getCurrentPosition(function (pos) {
        form.elements[names[0]].value = pos.coords.latitude.toFixed(6);
        form.elements[names[1]].value = pos.coords.longitude.toFixed(6);
        btn.textContent = label;
      }, function () {
        btn.textContent = label;
        alert('Could not get your location. Please allow location access or type the coordinates.');
      }, { enableHighAccuracy: true, timeout: 15000 });
    });
  });

  // Inspection: show the violation fields only when "violation" is ticked.
  var viol = document.getElementById('f_violation');
  if (viol) {
    var box = document.getElementById('violation-fields');
    var toggle = function () { box.hidden = !viol.checked; };
    viol.addEventListener('change', toggle);
    toggle();
  }
})();

// "Print / save as PDF" buttons (CSP forbids inline onclick handlers).
document.querySelectorAll('[data-print]').forEach(function (b) {
  b.addEventListener('click', function () { window.print(); });
});
