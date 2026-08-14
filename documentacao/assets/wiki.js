document.querySelectorAll('.copy-btn').forEach((button) => {
  button.addEventListener('click', async () => {
    const code = button.closest('.code-example')?.querySelector('code')?.textContent ?? '';
    try {
      await navigator.clipboard.writeText(code);
      button.textContent = 'Copiado!';
      bootstrap.Toast.getOrCreateInstance(document.getElementById('copyToast')).show();
      window.setTimeout(() => { button.textContent = 'Copiar'; }, 1600);
    } catch {
      button.textContent = 'Selecione o código';
      window.setTimeout(() => { button.textContent = 'Copiar'; }, 2000);
    }
  });
});

document.querySelectorAll('#wikiMenu .nav-link').forEach((link) => {
  link.addEventListener('click', () => {
    const menu = document.getElementById('wikiMenu');
    if (window.innerWidth < 992) bootstrap.Offcanvas.getOrCreateInstance(menu).hide();
  });
});
