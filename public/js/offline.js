document.addEventListener('click', (event) => {
    const retryButton = event.target.closest('#retry-button');
    if (!retryButton) {
        return;
    }

    window.location.reload();
});
