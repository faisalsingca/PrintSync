document.addEventListener('livewire:navigating', (event) => {
    event.detail.onSwap(() => {
        window.Flux.applyAppearance(window.localStorage.getItem('flux.appearance') || 'system');
    });
});
