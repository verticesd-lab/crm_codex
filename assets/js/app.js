document.addEventListener('DOMContentLoaded', () => {
  const flash = document.querySelector('[data-flash]');
  if (flash) {
    setTimeout(() => flash.classList.add('hidden'), 3500);
  }

  // Notifications dropdown
  const bell = document.querySelector('[data-bell]');
  const dropdown = document.querySelector('[data-bell-dropdown]');
  if (bell && dropdown) {
    bell.addEventListener('click', () => {
      dropdown.classList.toggle('hidden');
    });
    document.addEventListener('click', (e) => {
      if (!bell.contains(e.target) && !dropdown.contains(e.target)) {
        dropdown.classList.add('hidden');
      }
    });
  }
});
