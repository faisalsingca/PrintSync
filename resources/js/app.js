document.addEventListener('livewire:navigating', (event) => {
    event.detail.onSwap(() => {
        if (window.Flux) {
            window.Flux.applyAppearance(window.localStorage.getItem('flux.appearance') || 'system');
        }
    });
});