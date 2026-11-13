(() => {
  const key = 'remi-theme';
  const root = document.documentElement;
  const themeColor = document.querySelector('meta[name="theme-color"]');
  function setTheme(dark) {
    root.dataset.theme = dark ? 'dark' : 'light';
    themeColor.content = dark ? '#1d1511' : '#f8ecdc';
  }
  try {
    setTheme(localStorage.getItem(key) === 'dark');
  } catch {
    setTheme(false);
  }
  document.addEventListener('DOMContentLoaded', () => {
    const button = document.querySelector('#theme-toggle');
    button.setAttribute('aria-pressed', String(root.dataset.theme === 'dark'));
    button.hidden = false;
    button.addEventListener('click', () => {
      const dark = root.dataset.theme !== 'dark';
      setTheme(dark);
      button.setAttribute('aria-pressed', String(dark));
      try {
        localStorage.setItem(key, root.dataset.theme);
      } catch {
        // The current page still switches theme when storage is unavailable.
      }
    });
  });
})();
