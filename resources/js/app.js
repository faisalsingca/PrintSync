import { Passkeys } from '@laravel/passkeys';

document.addEventListener('livewire:navigating', (event) => {
    const isDark = document.documentElement.classList.contains('dark');

    event.detail.onSwap(() => {
        document.documentElement.classList.toggle('dark', isDark);
    });

});

document.addEventListener('click', async (event) => {
    if (!(event.target instanceof Element)) {
        return;
    }

    const button = event.target.closest('[data-passkey-verify]');

    if (!(button instanceof HTMLButtonElement)) {
        return;
    }

    const error = button.closest('[data-passkey-verification]')?.querySelector('[data-passkey-error]');

    if (!(error instanceof HTMLElement)) {
        return;
    }

    button.disabled = true;
    button.textContent = button.dataset.loadingLabel ?? '';
    error.textContent = '';

    try {
        const response = await Passkeys.verify({
            routes: {
                options: button.dataset.optionsUrl,
                submit: button.dataset.submitUrl,
            },
        });

        if (response.redirect) {
            window.location.assign(response.redirect);
        } else {
            window.location.reload();
        }
    } catch (exception) {
        error.textContent = exception instanceof Error
            ? exception.message
            : 'Passkey sign-in failed. Please try again.';
    } finally {
        button.disabled = false;
        button.textContent = button.dataset.label ?? '';
    }
});