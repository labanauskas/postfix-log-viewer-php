/* Apply the saved light/dark theme before first paint (external file: the CSP blocks inline scripts). */
try { var t = localStorage.getItem('adminkit-theme'); if (t) document.documentElement.setAttribute('data-theme', t); } catch (e) {}
