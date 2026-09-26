// Automatischer Reload, sobald das Gerät wieder eine Verbindung hat
window.addEventListener('online', () => {
    window.location.reload();
});

// Manueller Reload per Button-Klick
document.addEventListener('click', (event) => {
    const retryButton = event.target.closest('#retry-button');
    if (!retryButton) {
        return;
    }

    window.location.reload();
});

