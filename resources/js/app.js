document.addEventListener('livewire:navigating', (event) => {
    const isDark = document.documentElement.classList.contains('dark');

    event.detail.onSwap(() => {
        document.documentElement.classList.toggle('dark', isDark);
    });
});