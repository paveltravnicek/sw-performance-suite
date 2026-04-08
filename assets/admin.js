document.addEventListener('DOMContentLoaded', function () {
  var accordion = document.querySelector('[data-swps-accordion]');
  if (!accordion) return;

  var triggers = accordion.querySelectorAll('.swps-accordion-trigger');

  function closeAll(except) {
    triggers.forEach(function (trigger) {
      var panel = document.getElementById(trigger.getAttribute('aria-controls'));
      var isCurrent = except && trigger === except;
      trigger.setAttribute('aria-expanded', isCurrent ? 'true' : 'false');
      if (panel) {
        if (isCurrent) {
          panel.hidden = false;
        } else {
          panel.hidden = true;
        }
      }
    });
  }

  triggers.forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      var expanded = trigger.getAttribute('aria-expanded') === 'true';
      if (expanded) {
        closeAll(null);
      } else {
        closeAll(trigger);
      }
    });
  });
});
